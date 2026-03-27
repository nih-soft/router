<?php

declare(strict_types=1);

namespace NIH\Router\Middleware;

use NIH\Router\RouteMatchResult;
use NIH\Router\RouteMatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class RouteMatchMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RouteMatcher $matcher,
        private string $attributeName = RouteMatchResult::class,
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $matchResult = $this->matcher->match(
            $request->getUri()->getPath(),
            $request->getMethod(),
            $this->siteFromRequest($request),
            $request->getQueryParams(),
        );

        return $handler->handle($request->withAttribute($this->attributeName, $matchResult));
    }

    private function siteFromRequest(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $scheme = $uri->getScheme();
        $authority = $uri->getAuthority();

        if ($scheme === '' || $authority === '') {
            return '';
        }

        return $scheme . '://' . $authority;
    }
}
