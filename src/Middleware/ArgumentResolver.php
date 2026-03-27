<?php

declare(strict_types=1);

namespace NIH\Router\Middleware;

use Closure;
use NIH\Router\Middleware\Attribute\FromAttribute;
use NIH\Router\Middleware\Attribute\FromQuery;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionException;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;
use stdClass;
use Stringable;

abstract class ArgumentResolver
{
    /** Sentinel for unresolved argument values. */
    private static stdClass $unresolved;

    /**
     * @return array<string, mixed>
     * @throws ReflectionException
     */
    public static function resolveCallableArguments(
        Closure $callable,
        ServerRequestInterface $request,
        ?ResponseInterface $response = null,
    ): array {
        self::$unresolved ??= new stdClass();
        $arguments = [];
        $reflection = new ReflectionFunction($callable);

        foreach ($reflection->getParameters() as $parameter) {
            $argumentValue = self::resolveParameterArgument($request, $parameter, $response);

            if ($argumentValue !== self::$unresolved) {
                $arguments[$parameter->getName()] = $argumentValue;
            }
        }

        return $arguments;
    }

    private static function resolveParameterArgument(
        ServerRequestInterface $request,
        ReflectionParameter $parameter,
        ?ResponseInterface $response,
    ): mixed {
        $sourceAttribute = self::resolveSourceAttribute($parameter);

        if ($sourceAttribute !== null) {
            return self::resolveSourceArgument($request, $parameter, $sourceAttribute);
        }

        $runtimeValue = self::resolveRuntimeArgument($request, $parameter, $response);

        return $runtimeValue !== self::$unresolved
            ? $runtimeValue
            : self::resolveAutoParameterValue($request, $parameter);
    }

    private static function resolveRuntimeArgument(
        ServerRequestInterface $request,
        ReflectionParameter $parameter,
        ?ResponseInterface $response,
    ): mixed {
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return self::$unresolved;
        }

        $typeName = $type->getName();

        if (is_a($typeName, ServerRequestInterface::class, true)) {
            return $request;
        }

        if (is_a($typeName, ResponseInterface::class, true)) {
            // Reserved runtime parameters never fall back to route attributes or query params.
            if ($response instanceof $typeName) {
                return $response;
            }

            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }

            if ($parameter->allowsNull()) {
                return null;
            }

            throw new RuntimeException(sprintf(
                'Dispatch runtime could not resolve required parameter $%s of type %s.',
                $parameter->getName(),
                $typeName,
            ));
        }

        return self::$unresolved;
    }

    private static function resolveAutoParameterValue(
        ServerRequestInterface $request,
        ReflectionParameter $parameter,
    ): mixed {
        $name = $parameter->getName();
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType) {
            $attributeValue = $request->getAttribute($name, self::$unresolved);

            return $attributeValue !== self::$unresolved
                ? $attributeValue
                : self::readQueryValue($request, $name);
        }

        $typeName = $type->getName();

        $attributeValue = $request->getAttribute($name, self::$unresolved);

        if (!$type->isBuiltin()) {
            return $attributeValue instanceof $typeName ? $attributeValue : self::$unresolved;
        }

        if ($typeName === 'array') {

            if ($attributeValue !== self::$unresolved) {
                return is_array($attributeValue) ? $attributeValue : self::$unresolved;
            }

            $queryValue = self::readQueryValue($request, $name);

            return is_array($queryValue) ? $queryValue : self::$unresolved;
        }

        if ($attributeValue !== self::$unresolved) {
            // For baseline autoresolve, attribute presence wins over query fallback.
            return self::castAttributeScalar($attributeValue, $typeName);
        }

        $queryValue = self::readQueryValue($request, $name);

        return $queryValue === self::$unresolved
            ? self::$unresolved
            : self::castQueryScalar($queryValue, $typeName);
    }

    private static function resolveSourceAttribute(ReflectionParameter $parameter): FromAttribute|FromQuery|null
    {
        $sourceAttribute = null;

        foreach ($parameter->getAttributes() as $attribute) {
            $attributeClass = $attribute->getName();

            if ($attributeClass !== FromAttribute::class && $attributeClass !== FromQuery::class) {
                continue;
            }

            if ($sourceAttribute !== null) {
                throw new RuntimeException(sprintf(
                    'Parameter $%s must not declare more than one route source attribute.',
                    $parameter->getName(),
                ));
            }

            /** @var FromAttribute|FromQuery $sourceAttribute */
            $sourceAttribute = $attribute->newInstance();
        }

        return $sourceAttribute;
    }

    private static function resolveSourceArgument(
        ServerRequestInterface $request,
        ReflectionParameter $parameter,
        FromAttribute|FromQuery $sourceAttribute,
    ): mixed {
        $fromQuery = $sourceAttribute instanceof FromQuery;
        $sourceName = $fromQuery ? 'FromQuery' : 'FromAttribute';
        $key = $sourceAttribute->key ?? $parameter->getName();
        // Explicit source attributes disable cross-source fallback.
        $value = $fromQuery
            ? self::readQueryValue($request, $key)
            : $request->getAttribute($key, self::$unresolved);

        if ($value === self::$unresolved) {
            return self::resolveMissingSourceArgument($parameter, $sourceName, $key);
        }

        return self::resolveSourceValue($parameter, $value, $sourceName, $key, $fromQuery);
    }

    private static function resolveMissingSourceArgument(
        ReflectionParameter $parameter,
        string $sourceName,
        string $key,
    ): mixed {
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        throw new RuntimeException(sprintf(
            '%s could not resolve required parameter $%s from key "%s".',
            $sourceName,
            $parameter->getName(),
            $key,
        ));
    }

    private static function resolveSourceValue(
        ReflectionParameter $parameter,
        mixed $value,
        string $sourceName,
        string $key,
        bool $fromQuery,
    ): mixed {
        $type = $parameter->getType();

        if ($type === null) {
            return $value;
        }

        if (!$type instanceof ReflectionNamedType) {
            throw new RuntimeException(sprintf(
                '%s does not support non-named parameter type on $%s.',
                $sourceName,
                $parameter->getName(),
            ));
        }

        $typeName = $type->getName();

        if (!$type->isBuiltin()) {
            if ($fromQuery) {
                throw new RuntimeException(sprintf(
                    'FromQuery does not support object parameter $%s of type %s.',
                    $parameter->getName(),
                    $typeName,
                ));
            }

            if ($value instanceof $typeName) {
                return $value;
            }

            throw new RuntimeException(sprintf(
                '%s key "%s" resolved incompatible value for parameter $%s. Expected %s.',
                $sourceName,
                $key,
                $parameter->getName(),
                $typeName,
            ));
        }

        return self::resolveTypedSourceBuiltinValue(
            $parameter,
            $value,
            $type->getName(),
            $sourceName,
            $key,
            $fromQuery,
        );
    }

    private static function resolveTypedSourceBuiltinValue(
        ReflectionParameter $parameter,
        mixed $value,
        string $typeName,
        string $sourceName,
        string $key,
        bool $fromQuery,
    ): mixed {
        if ($typeName === 'mixed') {
            return $value;
        }

        if ($typeName === 'array') {
            return is_array($value)
                ? $value
                : self::resolveMissingSourceArgument($parameter, $sourceName, $key);
        }

        if ($fromQuery) {
            if (!in_array($typeName, ['int', 'float', 'string'], true)) {
                throw new RuntimeException(sprintf(
                    'FromQuery does not support parameter $%s of type %s.',
                    $parameter->getName(),
                    $typeName,
                ));
            }

            $castValue = self::castQueryScalar($value, $typeName);

            return $castValue === self::$unresolved
                ? self::resolveMissingSourceArgument($parameter, 'FromQuery', $key)
                : $castValue;
        }

        if (!in_array($typeName, ['int', 'float', 'string', 'bool'], true)) {
            throw new RuntimeException(sprintf(
                '%s key "%s" cannot be used for parameter $%s of type %s.',
                $sourceName,
                $key,
                $parameter->getName(),
                $typeName,
            ));
        }

        $castValue = self::castAttributeScalar($value, $typeName);

        return $castValue === self::$unresolved
            ? self::resolveMissingSourceArgument($parameter, $sourceName, $key)
            : $castValue;
    }

    private static function readQueryValue(
        ServerRequestInterface $request,
        string $key,
    ): mixed {
        $queryParams = $request->getQueryParams();

        // array_key_exists() preserves explicit null values in manually built requests.
        if (!array_key_exists($key, $queryParams)) {
            return self::$unresolved;
        }

        return $queryParams[$key];
    }

    private static function castAttributeScalar(mixed $value, string $typeName): mixed
    {
        return match ($typeName) {
            'int' => self::castIntValue($value, false),
            'float' => self::castFloatValue($value, false),
            'string' => self::castStringValue($value, false),
            'bool' => is_bool($value) ? $value : self::$unresolved,
            default => self::$unresolved,
        };
    }

    private static function castQueryScalar(mixed $value, string $typeName): stdClass|string|int|float
    {
        return match ($typeName) {
            'int' => self::castIntValue($value, true),
            'float' => self::castFloatValue($value, true),
            'string' => self::castStringValue($value, true),
            default => self::$unresolved,
        };
    }

    private static function castIntValue(mixed $value, bool $fromQuery): stdClass|int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (!$fromQuery && is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        return self::$unresolved;
    }

    private static function castFloatValue(mixed $value, bool $fromQuery): stdClass|float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        if (!$fromQuery && $value instanceof Stringable && is_numeric((string) $value)) {
            return (float) (string) $value;
        }

        return self::$unresolved;
    }

    private static function castStringValue(mixed $value, bool $fromQuery): stdClass|string
    {
        if (is_string($value)) {
            return $value;
        }

        if ($fromQuery) {
            return self::$unresolved;
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        return self::$unresolved;
    }

}
