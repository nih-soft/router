<?php

declare(strict_types=1);

namespace NIH\Router\Tests;

use NIH\Container\Container;
use NIH\Container\ContainerConfig;
use NIH\Router\Middleware\RouteDispatchMiddleware;
use NIH\Router\RouteMatchResult;
use NIH\Router\RouteMatcher;
use NIH\Router\Tests\Fixtures\Controllers\Dispatch\DispatchTrace;
use NIH\Router\Tests\Fixtures\Controllers\Dispatch\MiddlewareProbeController;
use NIH\Router\Tests\Fixtures\Http\FakeResponseFactory;
use NIH\Router\Tests\Fixtures\Http\FakeServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

final class RouteDispatchMiddlewareExecutionTest extends TestCase
{
    public function test_it_executes_local_controller_and_action_middleware_in_expected_order(): void
    {
        ['response' => $response, 'trace' => $trace] = $this->dispatchProbe('happy');

        self::assertSame([
            'controller-middleware-outer:enter',
            'controller-middleware-inner:enter',
            'controller-before',
            'action-before-first',
            'action-before-second',
            'action-before-second-value=',
            'action',
            'action-after-first',
            'action-after-second',
            'controller-after',
            'controller-middleware-inner:exit',
            'controller-middleware-outer:exit',
        ], $trace->all());
        self::assertSame('from-controller-before', $response->getHeaderLine('X-Controller-Before-Value'));
        self::assertSame('', $response->getHeaderLine('X-Action-Before-Value'));
        self::assertSame('', $response->getHeaderLine('X-Action-After-Value'));
    }

    public function test_it_short_circuits_on_action_before_and_skips_action_after(): void
    {
        ['response' => $response, 'trace' => $trace] = $this->dispatchProbe('happy', [
            'actionBeforeShortCircuit' => true,
        ]);

        self::assertSame(208, $response->getStatusCode());
        self::assertSame('action-before', $response->getHeaderLine('X-Short-Circuit'));
        self::assertSame([
            'controller-middleware-outer:enter',
            'controller-middleware-inner:enter',
            'controller-before',
            'action-before-first',
            'action-before-second',
            'action-before-second-value=',
            'controller-after',
            'controller-middleware-inner:exit',
            'controller-middleware-outer:exit',
        ], $trace->all());
    }

    public function test_it_short_circuits_on_controller_before_and_skips_inner_block(): void
    {
        ['response' => $response, 'trace' => $trace] = $this->dispatchProbe('happy', [
            'controllerBeforeShortCircuit' => true,
        ]);

        self::assertSame(209, $response->getStatusCode());
        self::assertSame('controller-before', $response->getHeaderLine('X-Short-Circuit'));
        self::assertSame([
            'controller-middleware-outer:enter',
            'controller-middleware-inner:enter',
            'controller-before',
            'controller-middleware-inner:exit',
            'controller-middleware-outer:exit',
        ], $trace->all());
    }

    public function test_it_propagates_request_replacement_between_sequential_before_and_after_callbacks(): void
    {
        ['response' => $response, 'trace' => $trace] = $this->dispatchProbe('happy', [
            'replaceRequestInActionBefore' => true,
            'replaceRequestInActionAfter' => true,
        ]);

        self::assertSame('from-action-before', $response->getHeaderLine('X-Action-Before-Value'));
        self::assertSame('from-action-after', $response->getHeaderLine('X-Action-After-Value'));
        self::assertContains('action-before-second-value=from-action-before', $trace->all());
    }

    public function test_it_replaces_response_in_after_middleware(): void
    {
        ['response' => $response] = $this->dispatchProbe('happy', [
            'replaceActionAfterResponse' => true,
        ]);

        self::assertSame('action', $response->getHeaderLine('X-After-Replaced'));
    }

    public function test_it_handles_action_exception_inside_controller_middleware(): void
    {
        ['response' => $response, 'trace' => $trace] = $this->dispatchProbe('throwsAction', [
            'handleExceptionAt' => 'controller-middleware-inner',
        ]);

        self::assertSame(560, $response->getStatusCode());
        self::assertSame('controller-middleware-inner', $response->getHeaderLine('X-Handled-By'));
        self::assertSame([
            'controller-middleware-outer:enter',
            'controller-middleware-inner:enter',
            'controller-before',
            'action-before-first',
            'action-before-second',
            'action-before-second-value=',
            'action',
            'controller-middleware-inner:exit',
            'controller-middleware-outer:exit',
        ], $trace->all());
    }

    public function test_it_bubbles_unhandled_controller_exception_out_of_dispatcher(): void
    {
        $trace = new DispatchTrace();
        $responseFactory = new FakeResponseFactory();
        $middleware = $this->createMiddleware(
            $this->createContainer($responseFactory, $trace),
            $responseFactory,
        );
        $request = $this->createFoundRequest(
            MiddlewareProbeController::class,
            'happy',
            [
                'controllerAfterThrows' => true,
            ],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('controller-after');

        try {
            $middleware->process($request, $this->createNeverCalledHandler());
        } finally {
            self::assertSame([
                'controller-middleware-outer:enter',
                'controller-middleware-inner:enter',
                'controller-before',
                'action-before-first',
                'action-before-second',
                'action-before-second-value=',
                'action',
                'action-after-first',
                'action-after-second',
                'controller-after',
                'controller-middleware-inner:exit',
                'controller-middleware-outer:exit',
            ], $trace->all());
        }
    }

    /**
     * @param array<string, mixed> $routeParams
     * @return array{
     *     response: ResponseInterface,
     *     trace: DispatchTrace
     * }
     */
    private function dispatchProbe(string $method, array $routeParams = []): array
    {
        $trace = new DispatchTrace();
        $responseFactory = new FakeResponseFactory();
        $middleware = $this->createMiddleware(
            $this->createContainer($responseFactory, $trace),
            $responseFactory,
        );
        $response = $middleware->process(
            $this->createFoundRequest(MiddlewareProbeController::class, $method, $routeParams),
            $this->createNeverCalledHandler(),
        );

        return [
            'response' => $response,
            'trace' => $trace,
        ];
    }

    private function createContainer(
        FakeResponseFactory $responseFactory,
        DispatchTrace $trace,
    ): Container {
        $config = new ContainerConfig();
        $config->value(ResponseFactoryInterface::class, $responseFactory);
        $config->value(DispatchTrace::class, $trace);

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
     */
    private function createFoundRequest(
        string $class,
        string $method,
        array $routeParams = [],
    ): FakeServerRequest {
        return (new FakeServerRequest('/dispatch/probe', 'GET'))->withAttribute(RouteMatchResult::class, $this->match([
            'status' => RouteMatcher::FOUND,
            'class' => $class,
            'method' => $method,
            'routeParams' => $routeParams,
            'queryParams' => [],
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
