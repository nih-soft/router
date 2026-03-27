<?php

declare(strict_types=1);

namespace NIH\Router;

use Psr\Http\Server\MiddlewareInterface;

final class RouteMatcher extends RouterData
{
    public const FOUND = 'FOUND';
    public const NOT_FOUND = 'NOT_FOUND';
    public const METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';

    public function __construct(RouterConfig $config)
    {
        $this->bindSiteRegistry($config);
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    public function match(string $path, string $httpMethod, string $site = '', array $queryParams = []): RouteMatchResult
    {
        $siteKey = $this->defaultSiteKey;
        $routeParams = [];

        // Resolve aliases and run site strategies only when an explicit site
        // string was provided by the caller.
        if ($site !== '') {
            $currentSite = '';

            // Normalize the incoming site once before resolving aliases.
            $site = strtolower(rtrim($site, '/'));
            $siteKey = $this->resolveSiteKey($site);

            if ($siteKey === null) {
                return $this->notFound($queryParams);
            }

            $currentSite = $site;

            if ($this->hasSiteStrategies) {
                foreach ($this->sites[$siteKey]['siteStrategies'] as &$siteStrategy) {
                    if (!$this->siteStrategyInstance($siteStrategy)->match($currentSite, $routeParams)) {
                        return $this->notFound($queryParams);
                    }
                }
                unset($siteStrategy);
            }
        }

        // Normalize the incoming path once before any tree traversal.
        $path = strtolower($path);

        if ($path !== '' && $path[0] === '/') {
            $path = substr($path, 1);
        }

        $node = &$this->sites[$siteKey]['root'];
        $middlewares = $node['middlewares'] ?? [];

        // Fast-reject roots without strategies by jumping directly to the
        // first structural child.
        if (!isset($node['strategies'][0])) {
            $firstSegment = UrlStringHelper::consumePathSegment($path);

            if ($firstSegment === null || !isset($node['children'][$firstSegment])) {
                return $this->notFound($queryParams);
            }

            $node = &$node['children'][$firstSegment];
            array_push($middlewares, ...($node['middlewares'] ?? []));
        }

        $allowedMethods = [];

        // Run strategies on the current node until one resolves a terminal
        // route, otherwise keep descending through structural children.
        while (true) {
            foreach ($node['strategies'] ?? [] as &$strategy) {
                $class = null;
                $method = null;

                if ($this->strategyInstance($strategy)->match(
                    $httpMethod,
                    $path,
                    $routeParams,
                    $queryParams,
                    $class,
                    $method,
                    $allowedMethods,
                )) {
                    return RouteMatchResult::found(
                        $class,
                        $method,
                        $routeParams,
                        $queryParams,
                        $middlewares,
                    );
                }
            }
            unset($strategy);

            $segment = UrlStringHelper::consumePathSegment($path);

            // A dead end after partial strategy matches becomes 405 if any
            // strategy advertised allowed methods, otherwise it is a 404.
            if ($segment === null || !isset($node['children'][$segment])) {
                if (!empty($allowedMethods)) {
                    return RouteMatchResult::methodNotAllowed(array_keys($allowedMethods), $queryParams);
                }

                return $this->notFound($queryParams);
            }

            $node = &$node['children'][$segment];
            array_push($middlewares, ...($node['middlewares'] ?? []));
        }
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    private function notFound(array $queryParams = []): RouteMatchResult
    {
        return RouteMatchResult::notFound($queryParams);
    }

}
