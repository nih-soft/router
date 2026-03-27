<?php

declare(strict_types=1);

namespace NIH\Router\Tests;

use NIH\Container\Container;
use NIH\Container\ContainerConfig;
use NIH\Router\Middleware\Attribute\After;
use NIH\Router\Middleware\Attribute\Before;
use NIH\Router\Middleware\Attribute\Middleware;
use NIH\Router\Middleware\RouteDispatchMiddleware;
use NIH\Router\RouteMatchResult;
use NIH\Router\RouteMatcher;
use NIH\Router\Tests\Fixtures\Controllers\Dispatch\DispatchTrace;
use NIH\Router\Tests\Fixtures\Controllers\Dispatch\ExecutionProbeController;
use NIH\Router\Tests\Fixtures\Controllers\Dispatch\RecordBeforeMiddleware;
use NIH\Router\Tests\Fixtures\Http\FakeResponseFactory;
use NIH\Router\Tests\Fixtures\Http\FakeServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use ReflectionNamedType;

final readonly class AliasedLocalMiddlewareController
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    #[Before(RecordBeforeMiddleware::class)]
    public function run(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('X-Request-Marker', (string) ($request->getAttribute('requestMarker') ?? ''));
    }
}

final readonly class ObjectAttributeRouteMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $request = $request->withAttribute('objectRouteMiddleware', 'yes');
        $response = $handler->handle($request);

        return $response->withHeader(
            'X-Object-Route',
            (string) $request->getAttribute('objectRouteMiddleware'),
        );
    }
}

final readonly class ObjectAttributeBeforeMiddleware
{
    public function __invoke(ServerRequestInterface $request): ServerRequestInterface
    {
        return $request->withAttribute('requestMarker', 'object-before');
    }
}

final readonly class ObjectAttributeAfterMiddleware
{
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        return $response->withHeader('X-Object-After', (string) $request->getAttribute('requestMarker'));
    }
}

#[Middleware(new ObjectAttributeRouteMiddleware())]
#[Before(new ObjectAttributeBeforeMiddleware())]
#[After(new ObjectAttributeAfterMiddleware())]
final readonly class ObjectAttributeMiddlewareController
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function run(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('X-Object-Before', (string) ($request->getAttribute('requestMarker') ?? ''))
            ->withHeader('X-Object-Route-Attr', (string) ($request->getAttribute('objectRouteMiddleware') ?? ''));
    }
}

final class RouteDispatchMiddlewareMetadataApiTest extends TestCase
{
    public function test_it_resolves_external_local_middleware_targets_from_container(): void
    {
        $trace = new DispatchTrace();
        $responseFactory = new FakeResponseFactory();
        $middleware = $this->createMiddleware(
            $this->createContainer($responseFactory, $trace),
            $responseFactory,
        );
        $response = $middleware->process(
            $this->createFoundRequest(ExecutionProbeController::class, 'lifecycle', [
                'replaceRequestAt' => 'action-before-1',
            ]),
            $this->createNeverCalledHandler(),
        );

        self::assertSame('action-before-1', $response->getHeaderLine('X-Action-Request'));
        self::assertSame([
            'controller-middleware:enter',
            'controller-before',
            'action-before-1',
            'action-before-2@action-before-1',
            'action@action-before-1',
            'action-after-1@action-before-1',
            'action-after-2@action-before-1',
            'controller-after@action-before-1',
            'controller-middleware:exit',
        ], $trace->all());
    }

    public function test_it_accepts_container_bound_local_middleware_targets_without_runtime_instanceof_check(): void
    {
        $responseFactory = new FakeResponseFactory();
        $config = new ContainerConfig();
        $config->value(ResponseFactoryInterface::class, $responseFactory);
        $config->value(RecordBeforeMiddleware::class, new class {
            public function __invoke(ServerRequestInterface $request): ServerRequestInterface
            {
                return $request->withAttribute('requestMarker', 'aliased-before');
            }
        });
        $container = new Container($config);
        $middleware = $this->createMiddleware($container, $responseFactory);

        $response = $middleware->process(
            $this->createFoundRequest(AliasedLocalMiddlewareController::class, 'run'),
            $this->createNeverCalledHandler(),
        );

        self::assertSame('aliased-before', $response->getHeaderLine('X-Request-Marker'));
    }

    public function test_it_accepts_object_instances_inside_metadata_attributes(): void
    {
        $responseFactory = new FakeResponseFactory();
        $middleware = $this->createMiddleware(
            $this->createContainer($responseFactory, new DispatchTrace()),
            $responseFactory,
        );

        $response = $middleware->process(
            $this->createFoundRequest(ObjectAttributeMiddlewareController::class, 'run'),
            $this->createNeverCalledHandler(),
        );

        self::assertSame('yes', $response->getHeaderLine('X-Object-Route'));
        self::assertSame('yes', $response->getHeaderLine('X-Object-Route-Attr'));
        self::assertSame('object-before', $response->getHeaderLine('X-Object-Before'));
        self::assertSame('object-before', $response->getHeaderLine('X-Object-After'));
    }

    public function test_action_after_request_replacement_does_not_leak_into_controller_after(): void
    {
        $trace = new DispatchTrace();
        $responseFactory = new FakeResponseFactory();
        $middleware = $this->createMiddleware(
            $this->createContainer($responseFactory, $trace),
            $responseFactory,
        );

        $middleware->process(
            $this->createFoundRequest(ExecutionProbeController::class, 'lifecycle', [
                'requestMarker' => 'initial',
                'replaceRequestAt' => 'action-after-1',
            ]),
            $this->createNeverCalledHandler(),
        );

        self::assertSame([
            'controller-middleware:enter',
            'controller-before@initial',
            'action-before-1@initial',
            'action-before-2@initial',
            'action@initial',
            'action-after-1@initial',
            'action-after-2@action-after-1',
            'controller-after@initial',
            'controller-middleware:exit',
        ], $trace->all());
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
        return (new FakeServerRequest('/articles/view', 'GET'))->withAttribute(RouteMatchResult::class, $this->match([
            'status' => RouteMatcher::FOUND,
            'class' => $class,
            'method' => $method,
            'routeParams' => $routeParams,
            'queryParams' => [],
        ]));
    }

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
