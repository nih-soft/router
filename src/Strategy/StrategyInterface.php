<?php

declare(strict_types=1);

namespace NIH\Router\Strategy;

interface StrategyInterface
{
    /**
     * @param array<string, mixed> $routeParams
     * @param array<string, mixed> $queryParams
     * @param array<string, true> $allowedMethods
     * @return bool True when the strategy produced a final match result.
     */
    public function match(
        string $httpMethod,
        string &$path,
        array &$routeParams,
        array &$queryParams,
        ?string &$class,
        ?string &$method,
        array &$allowedMethods,
    ): bool;

    /**
     * @param array<string, mixed> $queryParams Parameters passed by UrlGenerator.
     * @return bool True when the strategy produced a final result.
     */
    public function generate(
        string &$prefix,
        string &$path,
        array &$queryParams,
    ): bool;
}
