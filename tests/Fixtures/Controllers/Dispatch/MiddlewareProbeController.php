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

#[Middleware(MiddlewareProbeOuterRouteMiddleware::class)]
#[Middleware(MiddlewareProbeInnerRouteMiddleware::class)]
#[Before(self::class, 'controllerBefore')]
#[After(self::class, 'controllerAfter')]
final readonly class MiddlewareProbeController
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    #[Before(self::class, 'actionBeforeFirst')]
    #[Before(self::class, 'actionBeforeSecond')]
    #[After(self::class, 'actionAfterFirst')]
    #[After(self::class, 'actionAfterSecond')]
    public function happy(
        ServerRequestInterface $request,
        DispatchTrace $trace,
    ): ResponseInterface {
        $trace->add('action');

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('X-Controller-Before-Value', (string) ($request->getAttribute('controllerBeforeValue') ?? ''))
            ->withHeader('X-Action-Before-Value', (string) ($request->getAttribute('actionBeforeValue') ?? ''));
    }

    #[Before(self::class, 'actionBeforeFirst')]
    #[Before(self::class, 'actionBeforeSecond')]
    #[After(self::class, 'actionAfterFirst')]
    #[After(self::class, 'actionAfterSecond')]
    public function throwsAction(
        DispatchTrace $trace,
    ): ResponseInterface {
        $trace->add('action');

        throw new RuntimeException('action-boom');
    }

    public function controllerBefore(
        ServerRequestInterface $request,
        DispatchTrace $trace,
    ): ServerRequestInterface|ResponseInterface {
        $trace->add('controller-before');

        if ($request->getAttribute('controllerBeforeShortCircuit') === true) {
            return $this->responseFactory
                ->createResponse(209)
                ->withHeader('X-Short-Circuit', 'controller-before');
        }

        return $request->withAttribute('controllerBeforeValue', 'from-controller-before');
    }

    public function controllerAfter(
        ServerRequestInterface $request,
        ResponseInterface $response,
        DispatchTrace $trace,
    ): ResponseInterface {
        $trace->add('controller-after');

        if ($request->getAttribute('controllerAfterThrows') === true) {
            throw new RuntimeException('controller-after');
        }

        if ($request->getAttribute('replaceControllerAfterResponse') === true) {
            return $response->withHeader('X-After-Replaced', 'controller');
        }

        return $response;
    }

    public function actionBeforeFirst(
        ServerRequestInterface $request,
        DispatchTrace $trace,
    ): ?ServerRequestInterface {
        $trace->add('action-before-first');

        if ($request->getAttribute('replaceRequestInActionBefore') === true) {
            return $request->withAttribute('actionBeforeValue', 'from-action-before');
        }

        return null;
    }

    public function actionBeforeSecond(
        ServerRequestInterface $request,
        DispatchTrace $trace,
    ): ?ResponseInterface {
        $trace->add('action-before-second');
        $trace->add('action-before-second-value=' . (string) ($request->getAttribute('actionBeforeValue') ?? ''));

        if ($request->getAttribute('actionBeforeShortCircuit') === true) {
            return $this->responseFactory
                ->createResponse(208)
                ->withHeader('X-Short-Circuit', 'action-before');
        }

        return null;
    }

    public function actionAfterFirst(
        ServerRequestInterface $request,
        ResponseInterface $response,
        DispatchTrace $trace,
    ): ServerRequestInterface|ResponseInterface|null {
        $trace->add('action-after-first');

        if ($request->getAttribute('replaceRequestInActionAfter') === true) {
            return $request->withAttribute('actionAfterValue', 'from-action-after');
        }

        if ($request->getAttribute('replaceActionAfterResponse') === true) {
            return $response->withHeader('X-After-Replaced', 'action');
        }

        return null;
    }

    public function actionAfterSecond(
        ServerRequestInterface $request,
        ResponseInterface $response,
        DispatchTrace $trace,
    ): ResponseInterface {
        $trace->add('action-after-second');

        return $response->withHeader('X-Action-After-Value', (string) ($request->getAttribute('actionAfterValue') ?? ''));
    }
}
