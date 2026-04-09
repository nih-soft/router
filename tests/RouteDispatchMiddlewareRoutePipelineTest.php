<?php

declare(strict_types=1);

namespace NIH\Router\Tests;

use NIH\Container\Container;
use NIH\Container\ContainerConfig;
use NIH\MiddlewareDispatcher\MiddlewareDispatcher;
use NIH\MiddlewareDispatcher\Pipeline;
use NIH\MiddlewareDispatcher\PipelineControl;
use NIH\Router\Middleware\RouteDispatchMiddleware;
use NIH\Router\RouteMatchResult;
use NIH\Router\RouteMatcher;
use NIH\Router\RouterConfig;
use NIH\Router\Tests\Fixtures\Controllers\Dispatch\DispatchTrace;
use NIH\Router\Tests\Fixtures\Controllers\Dispatch\RoutePipelineController;
use NIH\Router\Tests\Fixtures\Http\FakeResponseFactory;
use NIH\Router\Tests\Fixtures\Http\FakeServerRequest;
use NIH\Router\Tests\Fixtures\Middleware\RecordRouteMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use ReflectionNamedType;

final class RouteDispatchMiddlewareRoutePipelineTest extends TestCase
{
    public function test_it_executes_router_path_middlewares_around_the_action_handler(): void
    {
        $trace = new DispatchTrace();
        $responseFactory = new FakeResponseFactory();
        $rootMiddleware = new RecordRouteMiddleware($responseFactory, $trace, 'root');
        $articlesMiddleware = new RecordRouteMiddleware($responseFactory, $trace, 'articles');
        $viewMiddleware = new RecordRouteMiddleware($responseFactory, $trace, 'view');
        $config = new RouterConfig();
        $config->path()->middleware($rootMiddleware);
        $config->path('/articles')
            ->middleware($articlesMiddleware)
            ->path('/view')
            ->middleware($viewMiddleware)
            ->action('', RoutePipelineController::class, 'show', ['GET']);

        $container = $this->createContainer($responseFactory, $trace);
        $matcher = new RouteMatcher($config);
        $match = $matcher->match('/articles/view', 'GET');
        $middleware = $this->createMiddleware($container, $responseFactory);

        self::assertSame([$rootMiddleware, $articlesMiddleware, $viewMiddleware], $match->middlewares);

        $response = $middleware->process(
            (new FakeServerRequest('/articles/view', 'GET'))->withAttribute(RouteMatchResult::class, $match),
            $this->createNeverCalledHandler(),
        );

        self::assertSame([
            'root:enter',
            'articles:enter',
            'view:enter',
            'controller-outer:enter',
            'controller-inner:enter',
            'action',
            'controller-inner:exit',
            'controller-outer:exit',
            'view:exit',
            'articles:exit',
            'root:exit',
        ], $trace->all());
        self::assertSame('root>articles>view>controller-outer>controller-inner', $response->getHeaderLine('X-Route-Pipeline'));
        self::assertSame(['controller-inner', 'controller-outer', 'view', 'articles', 'root'], $response->getHeader('X-Route-Middleware'));
    }

    public function test_it_executes_middleware_instances_from_router_config(): void
    {
        $trace = new DispatchTrace();
        $instanceMiddleware = new class($trace) implements MiddlewareInterface {
            public function __construct(
                private readonly DispatchTrace $trace,
            ) {
            }

            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                $this->trace->add('instance:enter');
                $current = $request->getAttribute('routePipeline');
                $current = is_string($current) && $current !== ''
                    ? $current . '>instance'
                    : 'instance';
                $request = $request->withAttribute('routePipeline', $current);
                $response = $handler->handle($request);
                $this->trace->add('instance:exit');

                return $response->withAddedHeader('X-Route-Middleware', 'instance');
            }
        };
        $responseFactory = new FakeResponseFactory();
        $articlesMiddleware = new RecordRouteMiddleware($responseFactory, $trace, 'articles');

        $config = new RouterConfig();
        $config->path()->middleware($instanceMiddleware);
        $config->path('/articles')
            ->middleware($articlesMiddleware)
            ->path('/view')
            ->action('', RoutePipelineController::class, 'show', ['GET']);

        $container = $this->createContainer($responseFactory, $trace);
        $matcher = new RouteMatcher($config);
        $match = $matcher->match('/articles/view', 'GET');
        $middleware = $this->createMiddleware($container, $responseFactory);

        self::assertSame($instanceMiddleware, $match->middlewares[0]);
        self::assertSame($articlesMiddleware, $match->middlewares[1]);

        $response = $middleware->process(
            (new FakeServerRequest('/articles/view', 'GET'))->withAttribute(RouteMatchResult::class, $match),
            $this->createNeverCalledHandler(),
        );

        self::assertSame([
            'instance:enter',
            'articles:enter',
            'controller-outer:enter',
            'controller-inner:enter',
            'action',
            'controller-inner:exit',
            'controller-outer:exit',
            'articles:exit',
            'instance:exit',
        ], $trace->all());
        self::assertSame(
            'instance>articles>controller-outer>controller-inner',
            $response->getHeaderLine('X-Route-Pipeline'),
        );
        self::assertSame(
            ['controller-inner', 'controller-outer', 'articles', 'instance'],
            $response->getHeader('X-Route-Middleware'),
        );
    }

    public function test_it_reuses_existing_dispatch_control_for_route_pipeline_execution(): void
    {
        $trace = new DispatchTrace();
        $responseFactory = new FakeResponseFactory();
        $dynamicMiddleware = new RecordRouteMiddleware($responseFactory, $trace, 'dynamic');
        $mutatingMiddleware = new class($trace, $dynamicMiddleware) implements MiddlewareInterface {
            public function __construct(
                private readonly DispatchTrace $trace,
                private readonly MiddlewareInterface $dynamicMiddleware,
            ) {
            }

            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                $this->trace->add('mutator:enter');
                $current = $request->getAttribute('routePipeline');
                $current = is_string($current) && $current !== ''
                    ? $current . '>mutator'
                    : 'mutator';
                $control = $request->getAttribute(PipelineControl::class);

                if (!$control instanceof PipelineControl) {
                    throw new \LogicException('Pipeline control attribute is missing.');
                }

                $control->prepend($this->dynamicMiddleware);

                $response = $handler->handle(
                    $request->withAttribute('routePipeline', $current),
                );
                $this->trace->add('mutator:exit');

                return $response->withAddedHeader('X-Route-Middleware', 'mutator');
            }
        };
        $config = new RouterConfig();
        $config->path()->middleware($mutatingMiddleware);
        $config->path('/articles')
            ->path('/view')
            ->action('', RoutePipelineController::class, 'show', ['GET']);

        $container = $this->createContainer($responseFactory, $trace);
        $matcher = new RouteMatcher($config);
        $match = $matcher->match('/articles/view', 'GET');
        $routeDispatchMiddleware = $this->createMiddleware($container, $responseFactory);
        $dispatcher = new MiddlewareDispatcher(
            $container,
            new Pipeline(
                [
                    new class($trace) implements MiddlewareInterface {
                        public function __construct(
                            private readonly DispatchTrace $trace,
                        ) {
                        }

                        public function process(
                            ServerRequestInterface $request,
                            RequestHandlerInterface $handler,
                        ): ResponseInterface {
                            $this->trace->add('outer:enter');
                            $response = $handler->handle($request);
                            $this->trace->add('outer:exit');

                            return $response;
                        }
                    },
                    $routeDispatchMiddleware,
                    new class($trace) implements MiddlewareInterface {
                        public function __construct(
                            private readonly DispatchTrace $trace,
                        ) {
                        }

                        public function process(
                            ServerRequestInterface $request,
                            RequestHandlerInterface $handler,
                        ): ResponseInterface {
                            $this->trace->add('downstream:enter');

                            throw new \LogicException('Downstream middleware must not be called.');
                        }
                    },
                ],
                new class implements RequestHandlerInterface {
                    public function handle(ServerRequestInterface $request): ResponseInterface
                    {
                        throw new \LogicException('Final handler must not be called.');
                    }
                },
            ),
        );

        $response = $dispatcher->handle(
            (new FakeServerRequest('/articles/view', 'GET'))
                ->withAttribute(RouteMatchResult::class, $match),
        );

        self::assertSame([
            'outer:enter',
            'mutator:enter',
            'dynamic:enter',
            'controller-outer:enter',
            'controller-inner:enter',
            'action',
            'controller-inner:exit',
            'controller-outer:exit',
            'dynamic:exit',
            'mutator:exit',
            'outer:exit',
        ], $trace->all());
        self::assertSame(
            'mutator>dynamic>controller-outer>controller-inner',
            $response->getHeaderLine('X-Route-Pipeline'),
        );
        self::assertSame(
            ['controller-inner', 'controller-outer', 'dynamic', 'mutator'],
            $response->getHeader('X-Route-Middleware'),
        );
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
}
