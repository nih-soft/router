<?php

declare(strict_types=1);

namespace NIH\Router\Tests;

use NIH\Container\Container;
use NIH\Container\ContainerConfig;
use NIH\Router\Middleware\RouteDispatchMiddleware;
use NIH\Router\RouteMatchResult;
use NIH\Router\RouteMatcher;
use NIH\Router\Tests\Fixtures\Controllers\Dispatch\AttributeProbeController;
use NIH\Router\Tests\Fixtures\Controllers\Dispatch\DispatchService;
use NIH\Router\Tests\Fixtures\Controllers\Dispatch\DispatchUser;
use NIH\Router\Tests\Fixtures\Controllers\Dispatch\ProbeController;
use NIH\Router\Tests\Fixtures\Http\FakeResponseFactory;
use NIH\Router\Tests\Fixtures\Http\FakeServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use ReflectionNamedType;
use Throwable;

final readonly class RouteMatchAttributeProbeController
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function capture(ServerRequestInterface $request): ResponseInterface
    {
        $value = $request->getAttribute(RouteMatchResult::class);

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('X-Route-Match-Is-Null', $value === null ? 'yes' : 'no')
            ->withHeader('X-Route-Match-Value', is_string($value) ? $value : '');
    }
}

final readonly class MagicCallableProbeController
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function __call(string $name, array $arguments): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('X-Magic-Method', $name)
            ->withHeader('X-Magic-Args', (string) count($arguments));
    }
}

final class RouteDispatchMiddlewareTest extends TestCase
{
    public function test_it_dispatches_found_match_and_applies_route_and_query_state(): void
    {
        $responseFactory = new FakeResponseFactory();
        $middleware = $this->createMiddleware($this->createContainer($responseFactory), $responseFactory);
        $request = (new FakeServerRequest('/articles/view', 'GET', queryParams: [
            'legacy' => '1',
        ]))->withAttribute(RouteMatchResult::class, $this->match([
            'status' => RouteMatcher::FOUND,
            'class' => ProbeController::class,
            'method' => 'capture',
            'routeParams' => [
                'id' => '42',
                'slug' => 'news',
                'user' => new DispatchUser('route-user'),
            ],
            'queryParams' => [
                'page' => '5',
                'filters' => ['kind' => 'new'],
            ],
        ]));

        $response = $middleware->process($request, $this->createNeverCalledHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('42', $response->getHeaderLine('X-Request-Id'));
        self::assertSame('5', $response->getHeaderLine('X-Request-Page'));
        self::assertSame('no', $response->getHeaderLine('X-Request-Legacy'));
        self::assertSame('news', $response->getHeaderLine('X-Slug'));
        self::assertSame('5', $response->getHeaderLine('X-Page'));
        self::assertSame('{"kind":"new"}', $response->getHeaderLine('X-Filters'));
        self::assertSame('route-user', $response->getHeaderLine('X-User'));
        self::assertSame('container-service', $response->getHeaderLine('X-Service'));
        self::assertSame('null', $response->getHeaderLine('X-Optional'));
        self::assertSame('recent', $response->getHeaderLine('X-Sort'));
    }

    public function test_it_does_not_fallback_to_query_when_scalar_attribute_key_exists(): void
    {
        $responseFactory = new FakeResponseFactory();
        $middleware = $this->createMiddleware($this->createContainer($responseFactory), $responseFactory);
        $request = (new FakeServerRequest('/articles/view', 'GET'))->withAttribute(RouteMatchResult::class, $this->match([
            'status' => RouteMatcher::FOUND,
            'class' => ProbeController::class,
            'method' => 'capture',
            'routeParams' => [
                'id' => '42',
                'slug' => 'news',
                'user' => new DispatchUser('route-user'),
                'sort' => ['invalid'],
            ],
            'queryParams' => [
                'page' => '5',
                'filters' => ['kind' => 'new'],
                'sort' => 'popular',
            ],
        ]));

        $response = $middleware->process($request, $this->createNeverCalledHandler());

        self::assertSame('recent', $response->getHeaderLine('X-Sort'));
    }

    public function test_it_clears_internal_route_match_attribute_before_inner_dispatch(): void
    {
        $responseFactory = new FakeResponseFactory();
        $middleware = $this->createMiddleware($this->createContainer($responseFactory), $responseFactory);
        $request = (new FakeServerRequest('/articles/view', 'GET'))->withAttribute(RouteMatchResult::class, $this->match([
            'status' => RouteMatcher::FOUND,
            'class' => RouteMatchAttributeProbeController::class,
            'method' => 'capture',
        ]));

        $response = $middleware->process($request, $this->createNeverCalledHandler());

        self::assertSame('yes', $response->getHeaderLine('X-Route-Match-Is-Null'));
        self::assertSame('', $response->getHeaderLine('X-Route-Match-Value'));
    }

    public function test_it_allows_route_params_to_reuse_the_internal_route_match_key(): void
    {
        $responseFactory = new FakeResponseFactory();
        $middleware = $this->createMiddleware($this->createContainer($responseFactory), $responseFactory);
        $request = (new FakeServerRequest('/articles/view', 'GET'))->withAttribute(RouteMatchResult::class, $this->match([
            'status' => RouteMatcher::FOUND,
            'class' => RouteMatchAttributeProbeController::class,
            'method' => 'capture',
            'routeParams' => [
                RouteMatchResult::class => 'route-param',
            ],
        ]));

        $response = $middleware->process($request, $this->createNeverCalledHandler());

        self::assertSame('no', $response->getHeaderLine('X-Route-Match-Is-Null'));
        self::assertSame('route-param', $response->getHeaderLine('X-Route-Match-Value'));
    }

    public function test_it_calls_the_next_handler_for_not_found(): void
    {
        $responseFactory = new FakeResponseFactory();
        $middleware = $this->createMiddleware($this->createContainer($responseFactory), $responseFactory);
        $match = $this->match([
            'status' => RouteMatcher::NOT_FOUND,
        ]);
        $request = (new FakeServerRequest('/missing', 'GET'))->withAttribute(RouteMatchResult::class, $match);
        $response = $responseFactory->createResponse(418)->withHeader('X-Fallback', 'yes');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(function (FakeServerRequest $request) use ($match): bool {
                self::assertSame($match, $request->getAttribute(RouteMatchResult::class));

                return true;
            }))
            ->willReturn($response);

        $result = $middleware->process($request, $handler);

        self::assertSame($response, $result);
        self::assertSame(418, $result->getStatusCode());
        self::assertSame('yes', $result->getHeaderLine('X-Fallback'));
    }

    public function test_it_returns_405_with_allow_header_for_method_not_allowed(): void
    {
        $responseFactory = new FakeResponseFactory();
        $middleware = $this->createMiddleware($this->createContainer($responseFactory), $responseFactory);
        $request = (new FakeServerRequest('/articles/view', 'POST'))->withAttribute(RouteMatchResult::class, $this->match([
            'status' => RouteMatcher::METHOD_NOT_ALLOWED,
            'allowedMethods' => ['GET', 'HEAD'],
        ]));

        $response = $middleware->process($request, $this->createNeverCalledHandler());

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
    }

    public function test_it_throws_when_route_match_attribute_is_missing(): void
    {
        $responseFactory = new FakeResponseFactory();
        $middleware = $this->createMiddleware($this->createContainer($responseFactory), $responseFactory);

        $this->expectException(Throwable::class);
        $this->expectExceptionMessage(RouteMatchResult::class);

        $middleware->process(new FakeServerRequest('/articles/view', 'GET'), $this->createNeverCalledHandler());
    }

    public function test_it_throws_when_route_match_attribute_has_an_invalid_type(): void
    {
        $responseFactory = new FakeResponseFactory();
        $middleware = $this->createMiddleware($this->createContainer($responseFactory), $responseFactory);
        $request = (new FakeServerRequest('/articles/view', 'GET'))->withAttribute(RouteMatchResult::class, new \stdClass());

        $this->expectException(Throwable::class);
        $this->expectExceptionMessage(RouteMatchResult::class);

        $middleware->process($request, $this->createNeverCalledHandler());
    }

    public function test_it_throws_when_action_does_not_return_a_response(): void
    {
        $responseFactory = new FakeResponseFactory();
        $middleware = $this->createMiddleware($this->createContainer($responseFactory), $responseFactory);
        $request = (new FakeServerRequest('/articles/view', 'GET'))->withAttribute(RouteMatchResult::class, $this->match([
            'status' => RouteMatcher::FOUND,
            'class' => ProbeController::class,
            'method' => 'returnsString',
        ]));

        $this->expectException(Throwable::class);
        $this->expectExceptionMessage('ResponseInterface');

        $middleware->process($request, $this->createNeverCalledHandler());
    }

    public function test_it_treats_a_non_callable_found_action_as_not_found(): void
    {
        $responseFactory = new FakeResponseFactory();
        $middleware = $this->createMiddleware($this->createContainer($responseFactory), $responseFactory);
        $request = (new FakeServerRequest('/articles/view', 'GET', queryParams: [
            'page' => '1',
        ]))->withAttribute(RouteMatchResult::class, $this->match([
            'status' => RouteMatcher::FOUND,
            'class' => ProbeController::class,
            'method' => 'missingAction',
            'routeParams' => [
                'id' => '42',
            ],
            'queryParams' => [
                'page' => '9',
            ],
        ]));
        $response = $responseFactory->createResponse(404)->withHeader('X-Fallback', 'not-found');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(function (FakeServerRequest $request): bool {
                self::assertNull($request->getAttribute('id'));
                self::assertSame(['page' => '1'], $request->getQueryParams());
                self::assertEquals(
                    RouteMatchResult::notFound(['page' => '1']),
                    $request->getAttribute(RouteMatchResult::class),
                );

                return true;
            }))
            ->willReturn($response);

        $result = $middleware->process($request, $handler);

        self::assertSame($response, $result);
        self::assertSame(404, $result->getStatusCode());
        self::assertSame('not-found', $result->getHeaderLine('X-Fallback'));
    }

    public function test_it_treats_magic_callable_targets_without_real_method_as_not_found(): void
    {
        $responseFactory = new FakeResponseFactory();
        $controller = new MagicCallableProbeController($responseFactory);
        $middleware = $this->createMiddleware(
            $this->createContainer($responseFactory, [
                MagicCallableProbeController::class => $controller,
            ]),
            $responseFactory,
        );
        $request = (new FakeServerRequest('/articles/view', 'GET', queryParams: [
            'page' => '3',
        ]))->withAttribute(RouteMatchResult::class, $this->match([
            'status' => RouteMatcher::FOUND,
            'class' => MagicCallableProbeController::class,
            'method' => 'missingAction',
            'queryParams' => [
                'page' => '9',
            ],
        ]));
        $response = $responseFactory->createResponse(404)->withHeader('X-Fallback', 'magic-not-found');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(function (FakeServerRequest $request): bool {
                self::assertSame(['page' => '3'], $request->getQueryParams());
                self::assertEquals(
                    RouteMatchResult::notFound(['page' => '3']),
                    $request->getAttribute(RouteMatchResult::class),
                );

                return true;
            }))
            ->willReturn($response);

        $result = $middleware->process($request, $handler);

        self::assertSame($response, $result);
        self::assertSame('magic-not-found', $result->getHeaderLine('X-Fallback'));
    }

    public function test_it_resolves_from_attribute_with_implicit_key(): void
    {
        $response = $this->dispatchAttributeProbe(
            method: 'fromAttributeImplicit',
            routeParams: [
                'user' => new DispatchUser('route-user'),
            ],
        );

        self::assertSame('route-user', $response->getHeaderLine('X-User'));
    }

    public function test_it_resolves_from_attribute_with_explicit_key(): void
    {
        $response = $this->dispatchAttributeProbe(
            method: 'fromAttributeExplicit',
            routeParams: [
                'currentUser' => new DispatchUser('current-user'),
                'user' => new DispatchUser('ignored-user'),
            ],
        );

        self::assertSame('current-user', $response->getHeaderLine('X-User'));
    }

    public function test_it_uses_nullable_and_default_for_missing_attribute_source_without_fallback(): void
    {
        $nullableResponse = $this->dispatchAttributeProbe(
            method: 'fromAttributeNullable',
            routeParams: [
                'user' => new DispatchUser('ignored-user'),
            ],
            queryParams: [
                'currentUser' => 'ignored-query',
            ],
        );

        self::assertSame('null', $nullableResponse->getHeaderLine('X-User'));

        $defaultResponse = $this->dispatchAttributeProbe(
            method: 'fromAttributeDefault',
            routeParams: [
                'sort' => ['invalid'],
            ],
            queryParams: [
                'sort' => 'ignored-query',
            ],
        );

        self::assertSame('recent', $defaultResponse->getHeaderLine('X-Sort'));
    }

    public function test_it_throws_when_attribute_source_object_type_does_not_match(): void
    {
        $responseFactory = new FakeResponseFactory();
        $middleware = $this->createMiddleware($this->createContainer($responseFactory), $responseFactory);
        $request = $this->createFoundRequest(
            class: AttributeProbeController::class,
            method: 'fromAttributeTypeMismatch',
            routeParams: [
                'user' => new DispatchService('wrong-type'),
            ],
        );

        $this->expectException(Throwable::class);

        $middleware->process($request, $this->createNeverCalledHandler());
    }

    public function test_it_resolves_from_query_with_implicit_key(): void
    {
        $response = $this->dispatchAttributeProbe(
            method: 'fromQueryImplicit',
            queryParams: [
                'page' => '12',
            ],
        );

        self::assertSame('12', $response->getHeaderLine('X-Page'));
    }

    public function test_it_resolves_from_query_with_explicit_key(): void
    {
        $response = $this->dispatchAttributeProbe(
            method: 'fromQueryExplicit',
            queryParams: [
                'p' => '14',
                'page' => '99',
            ],
        );

        self::assertSame('14', $response->getHeaderLine('X-Page'));
    }

    public function test_it_treats_invalid_query_cast_as_missing_and_uses_default_without_fallback(): void
    {
        $response = $this->dispatchAttributeProbe(
            method: 'fromQueryDefault',
            routeParams: [
                'page' => 42,
            ],
            queryParams: [
                'page' => 'invalid',
            ],
        );

        self::assertSame('7', $response->getHeaderLine('X-Page'));
    }

    public function test_it_uses_null_for_missing_query_source_after_invalid_cast(): void
    {
        $response = $this->dispatchAttributeProbe(
            method: 'fromQueryNullable',
            queryParams: [
                'page' => 'invalid',
            ],
        );

        self::assertSame('null', $response->getHeaderLine('X-Page'));
    }

    public function test_it_throws_for_unsupported_bool_from_query_source(): void
    {
        $responseFactory = new FakeResponseFactory();
        $middleware = $this->createMiddleware($this->createContainer($responseFactory), $responseFactory);
        $request = $this->createFoundRequest(
            class: AttributeProbeController::class,
            method: 'fromQueryBoolDefault',
            queryParams: [
                'flag' => '1',
            ],
        );

        $this->expectException(Throwable::class);

        $middleware->process($request, $this->createNeverCalledHandler());
    }

    public function test_it_throws_when_multiple_source_attributes_are_declared_for_one_parameter(): void
    {
        $responseFactory = new FakeResponseFactory();
        $middleware = $this->createMiddleware($this->createContainer($responseFactory), $responseFactory);
        $request = $this->createFoundRequest(
            class: AttributeProbeController::class,
            method: 'multipleSources',
            routeParams: [
                'value' => 'route-value',
            ],
            queryParams: [
                'value' => 'query-value',
            ],
        );

        $this->expectException(Throwable::class);

        $middleware->process($request, $this->createNeverCalledHandler());
    }

    private function createContainer(FakeResponseFactory $responseFactory, array $services = []): Container
    {
        $config = new ContainerConfig();
        $config->value(ResponseFactoryInterface::class, $responseFactory);
        $config->manual(DispatchService::class)->argument('name', 'container-service');

        foreach ($services as $id => $service) {
            $config->value($id, $service);
        }

        return new Container($config);
    }

    private function createMiddleware(
        Container $container,
        FakeResponseFactory $responseFactory,
        string $attributeName = RouteMatchResult::class,
    ): RouteDispatchMiddleware {
        $reflection = new ReflectionClass(RouteDispatchMiddleware::class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = match ($type->getName()) {
                    Container::class,
                    ContainerInterface::class => $container,
                    ResponseFactoryInterface::class => $responseFactory,
                    default => $parameter->isDefaultValueAvailable()
                        ? $parameter->getDefaultValue()
                        : throw new \LogicException(sprintf(
                            'Unsupported constructor dependency "%s" on %s.',
                            $type->getName(),
                            RouteDispatchMiddleware::class,
                        )),
                };

                continue;
            }

            if ($type instanceof ReflectionNamedType && $type->getName() === 'string') {
                $arguments[] = str_contains(strtolower($parameter->getName()), 'attribute')
                    ? $attributeName
                    : ($parameter->isDefaultValueAvailable()
                        ? $parameter->getDefaultValue()
                        : throw new \LogicException(sprintf(
                            'Unsupported string constructor parameter "$%s" on %s.',
                            $parameter->getName(),
                            RouteDispatchMiddleware::class,
                        )));

                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            throw new \LogicException(sprintf(
                'Unsupported constructor parameter "$%s" on %s.',
                $parameter->getName(),
                RouteDispatchMiddleware::class,
            ));
        }

        return $reflection->newInstanceArgs($arguments);
    }

    private function createNeverCalledHandler(): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        return $handler;
    }

    /**
     * @param array<string, mixed> $routeParams
     * @param array<string, mixed> $queryParams
     */
    private function dispatchAttributeProbe(
        string $method,
        array $routeParams = [],
        array $queryParams = [],
    ): \Psr\Http\Message\ResponseInterface {
        $responseFactory = new FakeResponseFactory();
        $middleware = $this->createMiddleware($this->createContainer($responseFactory), $responseFactory);
        $request = $this->createFoundRequest(
            class: AttributeProbeController::class,
            method: $method,
            routeParams: $routeParams,
            queryParams: $queryParams,
        );

        return $middleware->process($request, $this->createNeverCalledHandler());
    }

    /**
     * @param array<string, mixed> $routeParams
     * @param array<string, mixed> $queryParams
     */
    private function createFoundRequest(
        string $class,
        string $method,
        array $routeParams = [],
        array $queryParams = [],
    ): FakeServerRequest {
        return (new FakeServerRequest('/articles/view', 'GET'))->withAttribute(RouteMatchResult::class, $this->match([
            'status' => RouteMatcher::FOUND,
            'class' => $class,
            'method' => $method,
            'routeParams' => $routeParams,
            'queryParams' => $queryParams,
        ]));
    }

    /**
    /**
     * @param array{
     *     status?: string,
     *     class?: string,
     *     method?: string,
     *     routeParams?: array<string, mixed>,
     *     queryParams?: array<string, mixed>,
     *     allowedMethods?: list<string>,
     *     middlewares?: list<\Psr\Http\Server\MiddlewareInterface|string>
     * } $overrides
     */
    private function match(array $overrides): RouteMatchResult
    {
        $status = $overrides['status'] ?? RouteMatcher::NOT_FOUND;

        return match ($status) {
            RouteMatcher::FOUND => RouteMatchResult::found(
                $overrides['class'] ?? throw new \LogicException('FOUND test match must define "class".'),
                $overrides['method'] ?? throw new \LogicException('FOUND test match must define "method".'),
                $overrides['routeParams'] ?? [],
                $overrides['queryParams'] ?? null,
                $overrides['middlewares'] ?? [],
            ),
            RouteMatcher::NOT_FOUND => RouteMatchResult::notFound($overrides['queryParams'] ?? []),
            RouteMatcher::METHOD_NOT_ALLOWED => RouteMatchResult::methodNotAllowed(
                $overrides['allowedMethods'] ?? [],
                $overrides['queryParams'] ?? [],
            ),
            default => throw new \LogicException(sprintf('Unsupported match status "%s".', $status)),
        };
    }
}
