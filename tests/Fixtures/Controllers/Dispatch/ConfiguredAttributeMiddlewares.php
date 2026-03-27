<?php

declare(strict_types=1);

namespace NIH\Router\Tests\Fixtures\Controllers\Dispatch;

use NIH\Router\Tests\Fixtures\Middleware\RecordRouteMiddleware;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

abstract readonly class LabeledRouteMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private DispatchTrace $trace,
    ) {
    }

    final public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        return (new RecordRouteMiddleware(
            $this->responseFactory,
            $this->trace,
            $this->label(),
        ))->process($request, $handler);
    }

    abstract protected function label(): string;
}

abstract readonly class LabeledBeforeMiddleware
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private DispatchTrace $trace,
    ) {
    }

    final public function __invoke(
        ServerRequestInterface $request,
    ): ServerRequestInterface|ResponseInterface|null {
        return (new RecordBeforeMiddleware(
            $this->responseFactory,
            $this->trace,
            $this->label(),
        ))($request);
    }

    abstract protected function label(): string;
}

abstract readonly class LabeledAfterMiddleware
{
    public function __construct(
        private DispatchTrace $trace,
    ) {
    }

    final public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ServerRequestInterface|ResponseInterface|null {
        return (new RecordAfterMiddleware(
            $this->trace,
            $this->label(),
        ))($request, $response);
    }

    abstract protected function label(): string;
}

final readonly class RoutePipelineOuterRouteMiddleware extends LabeledRouteMiddleware
{
    protected function label(): string
    {
        return 'controller-outer';
    }
}

final readonly class RoutePipelineInnerRouteMiddleware extends LabeledRouteMiddleware
{
    protected function label(): string
    {
        return 'controller-inner';
    }
}

final readonly class MiddlewareProbeOuterRouteMiddleware extends LabeledRouteMiddleware
{
    protected function label(): string
    {
        return 'controller-middleware-outer';
    }
}

final readonly class MiddlewareProbeInnerRouteMiddleware extends LabeledRouteMiddleware
{
    protected function label(): string
    {
        return 'controller-middleware-inner';
    }
}

final readonly class ExecutionProbeRouteMiddleware extends LabeledRouteMiddleware
{
    protected function label(): string
    {
        return 'controller-middleware';
    }
}

final readonly class ExecutionProbeControllerBeforeMiddleware extends LabeledBeforeMiddleware
{
    protected function label(): string
    {
        return 'controller-before';
    }
}

final readonly class ExecutionProbeActionBeforeOneMiddleware extends LabeledBeforeMiddleware
{
    protected function label(): string
    {
        return 'action-before-1';
    }
}

final readonly class ExecutionProbeActionBeforeTwoMiddleware extends LabeledBeforeMiddleware
{
    protected function label(): string
    {
        return 'action-before-2';
    }
}

final readonly class ExecutionProbeControllerAfterMiddleware extends LabeledAfterMiddleware
{
    protected function label(): string
    {
        return 'controller-after';
    }
}

final readonly class ExecutionProbeActionAfterOneMiddleware extends LabeledAfterMiddleware
{
    protected function label(): string
    {
        return 'action-after-1';
    }
}

final readonly class ExecutionProbeActionAfterTwoMiddleware extends LabeledAfterMiddleware
{
    protected function label(): string
    {
        return 'action-after-2';
    }
}
