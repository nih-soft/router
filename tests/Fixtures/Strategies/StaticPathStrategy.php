<?php

declare(strict_types=1);

namespace NIH\Router\Tests\Fixtures\Strategies;

use NIH\Router\Strategy\StrategyInterface;

final readonly class StaticPathStrategy implements StrategyInterface
{
    public function __construct(
        private readonly string $path,
        private readonly string $class,
        private readonly string $method,
        private readonly array $allowedMethods = [],
    ) {
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
        $targetPath = $this->path;
        $targetClass = $this->class;
        $targetMethod = $this->method;
        $supportedMethods = $this->allowedMethods;

        if ($this->normalizeMatchPath($path) !== $targetPath) {
            return false;
        }

        if (!in_array($httpMethod, $supportedMethods, true)) {
            foreach ($supportedMethods as $allowedHttpMethod) {
                $allowedMethods[$allowedHttpMethod] = true;
            }

            return false;
        }

        $class = $targetClass;
        $method = strtolower($targetMethod);

        return true;
    }

    public function generate(
        string &$prefix,
        string &$path,
        array &$queryParams,
    ): bool
    {
        $targetPath = $this->path;
        $normalizedTargetPath = $this->normalizePath($targetPath);

        if ($path !== $normalizedTargetPath) {
            return false;
        }

        $prefix = $this->append($prefix, $normalizedTargetPath);
        $path = '';

        return true;
    }

    private function normalizeMatchPath(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        if ($path[0] === '/') {
            return '/' . $path;
        }

        if (str_ends_with($path, '/')) {
            $path = substr($path, 0, -1);
        }

        return '/' . $path;
    }

    private function append(string $basePath, string $path): string
    {
        if ($path === '/' || $path === '') {
            return $basePath;
        }

        if ($basePath === '') {
            return $path;
        }

        if (str_ends_with($basePath, '/')) {
            return $basePath . $path;
        }

        return $basePath . '/' . $path;
    }

    private function normalizePath(string $path): string
    {
        return trim($path, '/');
    }
}
