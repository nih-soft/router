<?php

declare(strict_types=1);

namespace NIH\Router\Tests;

use NIH\Router\RouteMatcher;
use NIH\Router\RouterConfig;
use NIH\Router\Tests\Fixtures\Strategies\QueryRewriteMatchStrategy;
use PHPUnit\Framework\TestCase;

final class RouteMatcherQueryParamsTest extends TestCase
{
    public function test_match_keeps_input_query_params_immutable_and_returns_the_updated_query_bag(): void
    {
        $config = new RouterConfig();
        $config->path('/search')->strategy(QueryRewriteMatchStrategy::class, [
            'path' => '',
            'class' => 'App\\Controller\\SearchAction',
        ]);

        $matcher = new RouteMatcher($config);
        $queryParams = [
            'page' => '2',
            'token' => 'secret',
        ];

        $match = $matcher->match('/search', 'GET', '', $queryParams);

        self::assertSame(RouteMatcher::FOUND, $match->status);
        self::assertSame('App\\Controller\\SearchAction', $match->class);
        self::assertSame([
            'page' => '2',
            'token' => 'secret',
        ], $queryParams);
        self::assertSame([
            'page' => '3',
            'resolved' => 'yes',
        ], $match->queryParams);
    }
}
