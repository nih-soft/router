<?php

declare(strict_types=1);

namespace NIH\Router\Middleware;

use Closure;
use NIH\Container\Instantiator;
use NIH\MiddlewareDispatcher\MiddlewareDispatcher;
use NIH\Router\Middleware\Attribute\After;
use NIH\Router\Middleware\Attribute\Before;
use NIH\Router\Middleware\Attribute\Middleware as MiddlewareAttribute;
use NIH\Router\RouteMatchResult;
use NIH\Router\RouteMatcher;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final readonly class RouteDispatchMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ContainerInterface $container,
        private ResponseFactoryInterface $responseFactory,
        private string $attributeName = RouteMatchResult::class,
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $matchResult = $request->getAttribute($this->attributeName);

        if (!$matchResult instanceof RouteMatchResult) {
            throw new RuntimeException(sprintf(
                'Route match attribute "%s" must contain %s.',
                $this->attributeName,
                RouteMatchResult::class,
            ));
        }

        return match ($matchResult->status) {
            RouteMatcher::FOUND => $this->dispatchFound($request, $handler, $matchResult),
            RouteMatcher::NOT_FOUND => $handler->handle($request),
            RouteMatcher::METHOD_NOT_ALLOWED => $this->methodNotAllowedResponse($matchResult),
            default => throw new RuntimeException(sprintf('Unsupported route match status "%s".', $matchResult->status)),
        };
    }

    private function dispatchFound(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
        RouteMatchResult $matchResult,
    ): ResponseInterface {
        $target = $this->container->get($matchResult->class);

        if (!is_object($target)) {
            throw new RuntimeException('Route target resolved from container must be an object.');
        }

        if (!method_exists($target, $matchResult->method) || !is_callable([$target, $matchResult->method])) {
            $notFoundRequest = $request->withAttribute(
                $this->attributeName,
                RouteMatchResult::notFound($request->getQueryParams()),
            );

            return $handler->handle($notFoundRequest);
        }

        $request = $this->applyMatchContext($request, $matchResult);
        $actionHandler = $this->getActionHandler($target, $matchResult->method);
        $dispatcher = new MiddlewareDispatcher(
            $this->container,
            [
                ...$matchResult->middlewares,
                ...$this->targetMiddlewares($target),
            ],
            $actionHandler,
        );

        return $dispatcher->handle($request);
    }

    /**
     * @param object $target
     * @return list<MiddlewareInterface|class-string<MiddlewareInterface>>
     */
    private function targetMiddlewares(object $target): array
    {
        $middlewares = [];
        $targetReflection = new ReflectionClass($target);

        foreach ($targetReflection->getAttributes(MiddlewareAttribute::class) as $attribute) {
            /** @var MiddlewareAttribute $instance */
            $instance = $attribute->newInstance();
            $middlewares[] = $instance->class;
        }

        return $middlewares;
    }
    private function applyMatchContext(ServerRequestInterface $request, RouteMatchResult $matchResult): ServerRequestInterface
    {
        $request = $request->withoutAttribute($this->attributeName);

        if ($matchResult->queryParams !== null) {
            $request = $request->withQueryParams($matchResult->queryParams);
        }

        foreach ($matchResult->routeParams as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        return $request;
    }

    private function methodNotAllowedResponse(RouteMatchResult $matchResult): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(405);

        if ($matchResult->allowedMethods === []) {
            return $response;
        }

        return $response->withHeader('Allow', implode(', ', $matchResult->allowedMethods));
    }

    private function getActionHandler(object $target, string $method): RequestHandlerInterface
    {
        return new class($this->container, $target, $method) implements RequestHandlerInterface
        {
            private ?Instantiator $instantiator = null;

            public function __construct(
                private readonly ContainerInterface $container,
                private readonly object $target,
                private readonly string $method,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $classMiddlewares = $this->resolveActionMiddlewares(new ReflectionClass($this->target));
                $methodMiddlewares = $this->resolveActionMiddlewares(new ReflectionMethod($this->target, $this->method));

                return $this->dispatchMiddlewares($request, $classMiddlewares, $methodMiddlewares);
            }

            /**
             * @param ReflectionClass<object>|ReflectionMethod $reflection
             * @return array{
             *     before: list<Closure>,
             *     after: list<Closure>
             * }
             */
            private function resolveActionMiddlewares(
                ReflectionClass|ReflectionMethod $reflection,
            ): array {
                $middlewares = [
                    'before' => [],
                    'after' => [],
                ];

                foreach ($reflection->getAttributes() as $attribute) {
                    $attributeClass = $attribute->getName();

                    if ($attributeClass !== Before::class && $attributeClass !== After::class) {
                        continue;
                    }

                    /** @var Before|After $instance */
                    $instance = $attribute->newInstance();

                    $middlewares[$attributeClass === Before::class ? 'before' : 'after'][] = $this->resolveActionMiddleware(
                        $attributeClass === Before::class ? 'Before' : 'After',
                        $instance->class,
                        $instance->method,
                    );
                }

                return $middlewares;
            }

            /**
             * @return Closure
             */
            private function resolveActionMiddleware(
                string $attributeName,
                object|string $class,
                string $method,
            ): Closure {
                if ($class === '' || $method === '') {
                    throw new RuntimeException(sprintf(
                        '%s middleware class and method must be non-empty strings.',
                        $attributeName,
                    ));
                }

                if (is_object($class)) {
                    $target = $class;
                } elseif (is_a($this->target, $class)) {
                    $target = $this->target;
                } else {
                    $target = $this->container->get($class);

                    if (!is_object($target)) {
                        throw new RuntimeException(sprintf(
                            'Middleware target resolved for %s must be an object.',
                            $class,
                        ));
                    }
                }

                if (!is_callable([$target, $method])) {
                    throw new RuntimeException(sprintf(
                        '%s middleware %s::%s must be callable.',
                        $attributeName,
                        is_object($class) ? $class::class : $class,
                        $method,
                    ));
                }

                return $target->$method(...);
            }

            /**
             * @param array{
             *     before: list<Closure>,
             *     after: list<Closure>
             * } $classMiddlewares
             * @param array{
             *     before: list<Closure>,
             *     after: list<Closure>
             * } $methodMiddlewares
             */
            private function dispatchMiddlewares(
                ServerRequestInterface $request,
                array $classMiddlewares,
                array $methodMiddlewares,
            ): ResponseInterface {
                foreach ($classMiddlewares['before'] as $callable) {
                    $result = $this->invokeMiddlewareCallable($callable, $request);

                    if ($this->replaceRequestFromResult($result, $request)) {
                        continue;
                    }

                    $response = null;

                    if ($this->replaceResponseFromResult($result, $response)) {
                        return $response;
                    }
                }

                $response = null;

                foreach ($methodMiddlewares['before'] as $callable) {
                    $result = $this->invokeMiddlewareCallable($callable, $request);

                    if ($this->replaceRequestFromResult($result, $request)) {
                        continue;
                    }

                    if ($this->replaceResponseFromResult($result, $response)) {
                        break;
                    }
                }

                $controllerAfterRequest = $request;

                if (!$response instanceof ResponseInterface) {
                    $response = $this->invokeActionResponse($request);
                    $actionAfterRequest = $request;

                    foreach ($methodMiddlewares['after'] as $callable) {
                        $result = $this->invokeMiddlewareCallable($callable, $actionAfterRequest, $response);

                        if ($this->replaceRequestFromResult($result, $actionAfterRequest)) {
                            continue;
                        }

                        $this->replaceResponseFromResult($result, $response);
                    }
                }

                foreach ($classMiddlewares['after'] as $callable) {
                    $result = $this->invokeMiddlewareCallable($callable, $controllerAfterRequest, $response);

                    if ($this->replaceRequestFromResult($result, $controllerAfterRequest)) {
                        continue;
                    }

                    $this->replaceResponseFromResult($result, $response);
                }

                if (!$response instanceof ResponseInterface) {
                    throw new RuntimeException('Dispatch middleware level must produce a response.');
                }

                return $response;
            }

            private function invokeActionResponse(ServerRequestInterface $request): ResponseInterface
            {
                $action = Closure::fromCallable([$this->target, $this->method]);
                $response = $this->getInstantiator()->invoke(
                    $action,
                    ArgumentResolver::resolveCallableArguments($action, $request),
                );

                if ($response instanceof ResponseInterface) {
                    return $response;
                }

                throw new RuntimeException(sprintf(
                    'Route action %s::%s must return %s.',
                    $this->target::class,
                    $this->method,
                    ResponseInterface::class,
                ));
            }

            private function invokeMiddlewareCallable(
                Closure $callable,
                ServerRequestInterface $request,
                ?ResponseInterface $response = null,
            ): mixed {
                return $this->getInstantiator()->invoke(
                    $callable,
                    ArgumentResolver::resolveCallableArguments($callable, $request, $response),
                );
            }

            private function getInstantiator(): Instantiator
            {
                /** @var Instantiator */
                return $this->instantiator ??= $this->container->get(Instantiator::class);
            }

            private function replaceRequestFromResult(
                mixed $result,
                ServerRequestInterface &$request,
            ): bool {
                if (!$result instanceof ServerRequestInterface) {
                    return false;
                }

                $request = $result;

                return true;
            }

            private function replaceResponseFromResult(
                mixed $result,
                ?ResponseInterface &$response,
            ): bool {
                if (!$result instanceof ResponseInterface) {
                    return false;
                }

                $response = $result;

                return true;
            }
        };
    }
}
