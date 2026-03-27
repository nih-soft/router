<?php

declare(strict_types=1);

namespace NIH\Router\Strategy;

use RuntimeException;

final readonly class PathToClassStrategy implements StrategyInterface
{
    public function __construct(
        private readonly string $namespace,
    )
    {
        if ($this->namespace === '') {
            throw new RuntimeException('PathToClassStrategy requires a non-empty "namespace" parameter.');
        }

        if ($this->namespace[0] === '\\' || str_ends_with($this->namespace, '\\')) {
            throw new RuntimeException(sprintf(
                'PathToClassStrategy namespace must not start or end with "\\": %s',
                $this->namespace,
            ));
        }
    }

    public function match(
        string $httpMethod,
        string &$path,
        array &$routeParams,
        array &$queryParams,
        ?string &$class,
        ?string &$method,
        array &$allowedMethods,
    ): bool {
        // The default convention strategy resolves exactly one method-specific
        // class name, for example ViewGet or ViewPost. It does not scan sibling
        // classes to build an allowed-methods list, so a missing method-specific
        // class is treated as NO_MATCH rather than METHOD_NOT_ALLOWED.
        $suffix = ucfirst(strtolower($httpMethod));

        if ($path === '' || $path === '/') {
            $resolvedClass = $this->namespace . '\\' . $suffix;
        } elseif ($path[0] === '/' || str_contains($path, '//')) {
            return false;
        } elseif (strspn($path, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789/') !== strlen($path)) {
            return false;
        } else {
            $resolvedClass = $this->namespace
                . '\\'
                . str_replace('/', '\\', ucwords($path, '/'))
                . $suffix;
        }

        if (!class_exists($resolvedClass)) {
            return false;
        }

        $class = $resolvedClass;
        $method = '__invoke';

        return true;
    }

    public function generate(
        string &$prefix,
        string &$path,
        array &$queryParams,
    ): bool
    {
        if ($path === '/') {
            return true;
        }

        if ($path !== '' && !preg_match('~^[A-Za-z][A-Za-z0-9]*(?:/[A-Za-z][A-Za-z0-9]*)*/?$~', $path)) {
            return false;
        }

        if ($path !== '') {
            if ($prefix === '') {
                $prefix = $path;
            } else {
                $prefix .= str_ends_with($prefix, '/')
                    ? $path
                    : '/' . $path;
            }
        }
        $path = '';

        return true;
    }
}
