<?php

declare(strict_types=1);

namespace NIH\Router\Tests\Fixtures\Controllers\Dispatch;

use NIH\Router\Middleware\Attribute\After;
use NIH\Router\Middleware\Attribute\Before;
use NIH\Router\Middleware\Attribute\Middleware;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

#[Middleware(ExecutionProbeRouteMiddleware::class)]
#[Before(ExecutionProbeControllerBeforeMiddleware::class)]
#[After(ExecutionProbeControllerAfterMiddleware::class)]
final readonly class ExecutionProbeController
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private DispatchTrace $trace,
    ) {
    }

    #[Before(ExecutionProbeActionBeforeOneMiddleware::class)]
    #[Before(ExecutionProbeActionBeforeTwoMiddleware::class)]
    #[After(ExecutionProbeActionAfterOneMiddleware::class)]
    #[After(ExecutionProbeActionAfterTwoMiddleware::class)]
    public function lifecycle(ServerRequestInterface $request): ResponseInterface
    {
        $this->trace->add($this->event($request, 'action'));

        if ($request->getAttribute('throwAt') === 'action') {
            throw new RuntimeException('Thrown at action');
        }

        $marker = $request->getAttribute('requestMarker');

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('X-Action', 'yes')
            ->withHeader('X-Action-Request', is_string($marker) ? $marker : '');
    }

    private function event(ServerRequestInterface $request, string $event): string
    {
        $marker = $request->getAttribute('requestMarker');

        if (!is_string($marker) || $marker === '') {
            return $event;
        }

        return $event . '@' . $marker;
    }
}
