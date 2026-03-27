<?php

declare(strict_types=1);

namespace NIH\Router\Tests\Fixtures\Controllers\Dispatch;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final readonly class RecordAfterMiddleware
{
    public function __construct(
        private DispatchTrace $trace,
        private string $label,
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ServerRequestInterface|ResponseInterface|null {
        $this->trace->add($this->event($request));

        if ($request->getAttribute('throwAt') === $this->label) {
            throw new RuntimeException('Thrown at ' . $this->label);
        }

        if ($request->getAttribute('replaceRequestAt') === $this->label) {
            return $request->withAttribute('requestMarker', $this->label);
        }

        if ($request->getAttribute('replaceResponseAt') === $this->label) {
            return $response->withHeader('X-Replaced-By', $this->label);
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
