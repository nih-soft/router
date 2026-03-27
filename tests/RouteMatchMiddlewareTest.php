<?php

declare(strict_types=1);

namespace NIH\Router\Tests;

use NIH\Router\Middleware\RouteMatchMiddleware;
use NIH\Router\RouteMatchResult;
use NIH\Router\RouteMatcher;
use NIH\Router\RouterConfig;
use NIH\Router\Strategy\PathToClassStrategy;
use NIH\Router\Strategy\Site\SubdomainReaderSiteStrategy;
use NIH\Router\Tests\Fixtures\Http\FakeServerRequest;
use NIH\Router\Tests\Fixtures\Strategies\QueryRewriteMatchStrategy;
use NIH\Router\Tests\Fixtures\Strategies\ThrowingStrategy;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class RouteMatchMiddlewareTest extends TestCase
{
    public function test_it_writes_found_match_result_to_the_default_attribute_and_calls_the_next_handler(): void
    {
        $config = new RouterConfig();
        $config->path('/health')->action('', 'App\\Controller\\HealthAction', '__invoke', ['GET']);

        $middleware = new RouteMatchMiddleware(new RouteMatcher($config));
        $request = new FakeServerRequest('/health', 'GET');
        $response = $this->createMock(ResponseInterface::class);
        $expectedMatch = RouteMatchResult::found('App\\Controller\\HealthAction', '__invoke', [], []);

        $handler = $this->createHandlerExpectingAttribute(RouteMatchResult::class, $expectedMatch, $response);

        self::assertSame($response, $middleware->process($request, $handler));
    }

    public function test_it_writes_not_found_to_the_request_and_still_calls_the_next_handler(): void
    {
        $config = new RouterConfig();
        $config->path('/health')->action('', 'App\\Controller\\HealthAction', '__invoke', ['GET']);

        $middleware = new RouteMatchMiddleware(new RouteMatcher($config));
        $request = new FakeServerRequest('/missing', 'GET');
        $response = $this->createMock(ResponseInterface::class);
        $expectedMatch = RouteMatchResult::notFound();

        $handler = $this->createHandlerExpectingAttribute(RouteMatchResult::class, $expectedMatch, $response);

        self::assertSame($response, $middleware->process($request, $handler));
    }

    public function test_it_preserves_allowed_methods_when_the_matcher_returns_method_not_allowed(): void
    {
        $config = new RouterConfig();
        $config->path('/health')->action('', 'App\\Controller\\HealthAction', '__invoke', ['GET', 'HEAD']);

        $middleware = new RouteMatchMiddleware(new RouteMatcher($config));
        $request = new FakeServerRequest('/health', 'POST');
        $response = $this->createMock(ResponseInterface::class);
        $expectedMatch = RouteMatchResult::methodNotAllowed(['GET', 'HEAD']);

        $handler = $this->createHandlerExpectingAttribute(RouteMatchResult::class, $expectedMatch, $response);

        self::assertSame($response, $middleware->process($request, $handler));
    }

    public function test_it_can_use_a_custom_attribute_name(): void
    {
        $config = new RouterConfig();
        $config->path('/health')->action('', 'App\\Controller\\HealthAction', '__invoke', ['GET']);

        $middleware = new RouteMatchMiddleware(new RouteMatcher($config), 'matchedRoute');
        $request = new FakeServerRequest('/health', 'GET');
        $response = $this->createMock(ResponseInterface::class);
        $expectedMatch = RouteMatchResult::found('App\\Controller\\HealthAction', '__invoke', [], []);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(function (ServerRequestInterface $request) use ($expectedMatch): bool {
                self::assertEquals($expectedMatch, $request->getAttribute('matchedRoute'));
                self::assertNull($request->getAttribute(RouteMatchResult::class));

                return true;
            }))
            ->willReturn($response);

        self::assertSame($response, $middleware->process($request, $handler));
    }

    public function test_it_uses_the_request_uri_site_when_matching_site_specific_routes(): void
    {
        $config = new RouterConfig();
        $config->site('https://www.example.com');
        $config->site('https://api.example.com')
            ->path('/ping')
            ->action('', 'Api\\Controller\\PingAction', '__invoke', ['GET']);

        $middleware = new RouteMatchMiddleware(new RouteMatcher($config));
        $request = new FakeServerRequest('/ping', 'GET', 'https', 'api.example.com');
        $response = $this->createMock(ResponseInterface::class);
        $expectedMatch = RouteMatchResult::found('Api\\Controller\\PingAction', '__invoke', [], []);

        $handler = $this->createHandlerExpectingAttribute(RouteMatchResult::class, $expectedMatch, $response);

        self::assertSame($response, $middleware->process($request, $handler));
    }

    public function test_it_skips_site_strategies_when_request_uri_has_no_site(): void
    {
        $config = new RouterConfig();
        $config->site('https://example.com')
            ->siteStrategy(SubdomainReaderSiteStrategy::class, [
                'required' => true,
            ])
            ->path('/pub/forums/')
            ->strategy(PathToClassStrategy::class, [
                'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums',
            ]);

        $middleware = new RouteMatchMiddleware(new RouteMatcher($config));
        $request = new FakeServerRequest('/pub/forums/list/view', 'GET');
        $response = $this->createMock(ResponseInterface::class);
        $expectedMatch = RouteMatchResult::found(
            'NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums\\List\\ViewGet',
            '__invoke',
            [],
            [],
        );

        $handler = $this->createHandlerExpectingAttribute(RouteMatchResult::class, $expectedMatch, $response);

        self::assertSame($response, $middleware->process($request, $handler));
    }

    public function test_it_passes_request_query_params_to_the_matcher(): void
    {
        $config = new RouterConfig();
        $config->path('/search')->strategy(QueryRewriteMatchStrategy::class, [
            'path' => '',
            'class' => 'App\\Controller\\SearchAction',
        ]);

        $middleware = new RouteMatchMiddleware(new RouteMatcher($config));
        $request = new FakeServerRequest('/search', 'GET', queryParams: [
            'page' => '2',
            'token' => 'secret',
        ]);
        $response = $this->createMock(ResponseInterface::class);
        $expectedMatch = RouteMatchResult::found(
            'App\\Controller\\SearchAction',
            '__invoke',
            [],
            [
                'page' => '3',
                'resolved' => 'yes',
            ],
        );

        $handler = $this->createHandlerExpectingAttribute(RouteMatchResult::class, $expectedMatch, $response);

        self::assertSame($response, $middleware->process($request, $handler));
    }

    public function test_it_propagates_matcher_exceptions_without_calling_the_next_handler(): void
    {
        $config = new RouterConfig();
        $config->path('/boom')->strategy(ThrowingStrategy::class);

        $middleware = new RouteMatchMiddleware(new RouteMatcher($config));
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Matcher failure.');

        $middleware->process(new FakeServerRequest('/boom', 'GET'), $handler);
    }

    private function createHandlerExpectingAttribute(
        string $attributeName,
        RouteMatchResult $expectedMatch,
        ResponseInterface $response,
    ): RequestHandlerInterface {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(function (ServerRequestInterface $request) use ($attributeName, $expectedMatch): bool {
                self::assertEquals($expectedMatch, $request->getAttribute($attributeName));

                return true;
            }))
            ->willReturn($response);

        return $handler;
    }
}
