<?php

declare(strict_types=1);

namespace NIH\Router;

use Psr\Http\Server\MiddlewareInterface;

final readonly class RouteMatchResult
{
    /**
     * @param array<string, mixed> $routeParams
     * @param ?array<string, mixed> $queryParams
     * @param list<string> $allowedMethods
     * @param list<MiddlewareInterface|class-string<MiddlewareInterface>> $middlewares
     */
    private function __construct(
        public string $status,
        public ?string $class,
        public ?string $method,
        public array $routeParams,
        public ?array $queryParams,
        public array $allowedMethods,
        public array $middlewares,
    ) {
    }

    /**
     * @param array<string, mixed> $routeParams
     * @param ?array<string, mixed> $queryParams
     * @param list<MiddlewareInterface|class-string<MiddlewareInterface>> $middlewares
     */
    public static function found(
        string $class,
        string $method,
        array $routeParams = [],
        ?array $queryParams = null,
        array $middlewares = [],
    ): self {
        return new self(
            RouteMatcher::FOUND,
            $class,
            $method,
            $routeParams,
            $queryParams,
            [],
            $middlewares,
        );
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    public static function notFound(array $queryParams = []): self
    {
        return new self(
            RouteMatcher::NOT_FOUND,
            null,
            null,
            [],
            $queryParams,
            [],
            [],
        );
    }

    /**
     * @param list<string> $allowedMethods
     * @param array<string, mixed> $queryParams
     */
    public static function methodNotAllowed(array $allowedMethods, array $queryParams = []): self
    {
        return new self(
            RouteMatcher::METHOD_NOT_ALLOWED,
            null,
            null,
            [],
            $queryParams,
            $allowedMethods,
            [],
        );
    }
}
