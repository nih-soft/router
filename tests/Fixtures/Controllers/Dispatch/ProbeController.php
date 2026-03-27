<?php

declare(strict_types=1);

namespace NIH\Router\Tests\Fixtures\Controllers\Dispatch;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ProbeController
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function capture(
        ServerRequestInterface $request,
        string $slug,
        int $page,
        array $filters,
        DispatchUser $user,
        DispatchService $service,
        ?DispatchOptionalDependencyInterface $optional = null,
        string $sort = 'recent',
    ): ResponseInterface {
        return $this->responseFactory->createResponse(200)
            ->withHeader('X-Request-Id', (string) $request->getAttribute('id'))
            ->withHeader('X-Request-Page', (string) ($request->getQueryParams()['page'] ?? ''))
            ->withHeader('X-Request-Legacy', array_key_exists('legacy', $request->getQueryParams()) ? 'yes' : 'no')
            ->withHeader('X-Slug', $slug)
            ->withHeader('X-Page', (string) $page)
            ->withHeader('X-Filters', json_encode($filters, JSON_THROW_ON_ERROR))
            ->withHeader('X-User', $user->id)
            ->withHeader('X-Service', $service->name)
            ->withHeader('X-Optional', $optional === null ? 'null' : 'resolved')
            ->withHeader('X-Sort', $sort);
    }

    public function returnsString(): string
    {
        return 'invalid';
    }
}
