<?php

declare(strict_types=1);

namespace NIH\Router\Tests\Fixtures\Controllers\Dispatch;

use NIH\Router\Middleware\Attribute\Middleware;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Middleware(RoutePipelineOuterRouteMiddleware::class)]
#[Middleware(RoutePipelineInnerRouteMiddleware::class)]
final readonly class RoutePipelineController
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private DispatchTrace $trace,
    ) {
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->trace->add('action');

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('X-Route-Pipeline', (string) ($request->getAttribute('routePipeline') ?? ''));
    }
}
