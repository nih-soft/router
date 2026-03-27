<?php

declare(strict_types=1);

namespace NIH\Router\Tests\Fixtures\Controllers\Dispatch;

use NIH\Router\Middleware\Attribute\FromAttribute;
use NIH\Router\Middleware\Attribute\FromQuery;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

final readonly class AttributeProbeController
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function fromAttributeImplicit(
        #[FromAttribute]
        DispatchUser $user,
    ): ResponseInterface {
        return $this->response('X-User', $user->id);
    }

    public function fromAttributeExplicit(
        #[FromAttribute('currentUser')]
        DispatchUser $user,
    ): ResponseInterface {
        return $this->response('X-User', $user->id);
    }

    public function fromAttributeNullable(
        #[FromAttribute('currentUser')]
        ?DispatchUser $user = null,
    ): ResponseInterface {
        return $this->response('X-User', $user?->id ?? 'null');
    }

    public function fromAttributeDefault(
        #[FromAttribute('sort')]
        string $sort = 'recent',
    ): ResponseInterface {
        return $this->response('X-Sort', $sort);
    }

    public function fromAttributeTypeMismatch(
        #[FromAttribute]
        DispatchUser $user,
    ): ResponseInterface {
        return $this->response('X-User', $user->id);
    }

    public function fromQueryImplicit(
        #[FromQuery]
        int $page,
    ): ResponseInterface {
        return $this->response('X-Page', (string) $page);
    }

    public function fromQueryExplicit(
        #[FromQuery('p')]
        int $page,
    ): ResponseInterface {
        return $this->response('X-Page', (string) $page);
    }

    public function fromQueryDefault(
        #[FromQuery('page')]
        int $page = 7,
    ): ResponseInterface {
        return $this->response('X-Page', (string) $page);
    }

    public function fromQueryNullable(
        #[FromQuery('page')]
        ?int $page = null,
    ): ResponseInterface {
        return $this->response('X-Page', $page === null ? 'null' : (string) $page);
    }

    public function fromQueryBoolDefault(
        #[FromQuery('flag')]
        bool $flag = false,
    ): ResponseInterface {
        return $this->response('X-Flag', $flag ? 'true' : 'false');
    }

    public function multipleSources(
        #[FromAttribute]
        #[FromQuery]
        string $value,
    ): ResponseInterface {
        return $this->response('X-Value', $value);
    }

    private function response(string $header, string $value): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse(200)
            ->withHeader($header, $value);
    }
}
