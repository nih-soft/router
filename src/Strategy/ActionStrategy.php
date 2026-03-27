<?php

declare(strict_types=1);

namespace NIH\Router\Strategy;

final readonly class ActionStrategy implements StrategyInterface
{
    private string $path;

    private string $class;

    private string $method;

    /**
     * @var list<string>
     */
    private array $allowedMethods;

    /**
     * @param list<string> $allowedMethods
     */
    public function __construct(
        string $path,
        string $class,
        string $method = '__invoke',
        array $allowedMethods = [],
    ) {
        $path = strtolower($path);

        if ($path !== '' && $path !== '/' && $path[0] === '/') {
            $path = substr($path, 1);
        }

        $this->path = $path;
        $this->class = $class;
        $this->method = $method;
        $this->allowedMethods = $allowedMethods;
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
        if ($path !== $this->path) {
            return false;
        }

        if ($this->allowedMethods !== [] && !in_array($httpMethod, $this->allowedMethods, true)) {
            foreach ($this->allowedMethods as $allowedHttpMethod) {
                $allowedMethods[$allowedHttpMethod] = true;
            }

            return false;
        }

        $class = $this->class;
        $method = $this->method;

        return true;
    }

    public function generate(
        string &$prefix,
        string &$path,
        array &$queryParams,
    ): bool {
        return $path === $this->path;
    }
}
