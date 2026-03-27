<?php

declare(strict_types=1);

namespace NIH\Router\Tests\Fixtures\Controllers\Dispatch;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final readonly class RecordBeforeMiddleware
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private DispatchTrace $trace,
        private string $label,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ServerRequestInterface|ResponseInterface|null
    {
        $this->trace->add($this->event($request));

        if ($request->getAttribute('throwAt') === $this->label) {
            throw new RuntimeException('Thrown at ' . $this->label);
        }

        if ($request->getAttribute('shortCircuitAt') === $this->label) {
            return $this->responseFactory
                ->createResponse(230)
                ->withHeader('X-Short-Circuit', $this->label);
        }

        if ($request->getAttribute('replaceRequestAt') === $this->label) {
            return $request->withAttribute('requestMarker', $this->label);
        }

        return null;
    }

    private function event(ServerRequestInterface $request): string
    {
        $marker = $request->getAttribute('requestMarker');

        if (!is_string($marker) || $marker === '') {
            return $this->label;
        }

        return $this->label . '@' . $marker;
    }
}
