<?php

declare(strict_types=1);

namespace NIH\Router\Tests;

use NIH\Router\Strategy\Consumer\PathTemplateConsumer;
use NIH\Router\RouteMatcher;
use NIH\Router\RouterConfig;
use NIH\Router\Strategy\PathToClassStrategy;
use NIH\Router\UrlGenerator;
use PHPUnit\Framework\TestCase;

final class PathTemplateConsumerIntegrationTest extends TestCase
{
    public function test_it_can_match_a_path_template_before_path_to_class_strategy(): void
    {
        $config = new RouterConfig();
        $config->path('/blogs/')
            ->strategy(PathTemplateConsumer::class, [
                'pattern' => '{blogId:int}/threads/',
            ])
            ->strategy(PathToClassStrategy::class, [
                'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Blogs\\Threads',
            ]);

        $matcher = new RouteMatcher($config);
        $generator = new UrlGenerator($config);
        $match = $matcher->match('/blogs/25/threads/view', 'GET');

        $this->assertSame(RouteMatcher::FOUND, $match->status);
        $this->assertSame(
            'NIH\\Router\\Tests\\Fixtures\\Controllers\\Blogs\\Threads\\ViewGet',
            $match->class,
        );
        $this->assertSame(['blogId' => '25'], $match->routeParams);
        $this->assertSame('/blogs/25/threads/view', $generator->generatePath('/blogs/view', ['blogId' => 25]));
    }

    public function test_it_ignores_a_trailing_strategy_slash_before_structural_child_generation(): void
    {
        $config = new RouterConfig();
        $config->path('/blogs/')
            ->strategy(PathTemplateConsumer::class, [
                'pattern' => '{blogId:int}/',
            ])
            ->path('/threads/')
            ->strategy(PathToClassStrategy::class, [
                'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Blogs\\Threads',
            ]);

        $matcher = new RouteMatcher($config);
        $generator = new UrlGenerator($config);
        $match = $matcher->match('/blogs/25/threads/view', 'GET');

        $this->assertSame(RouteMatcher::FOUND, $match->status);
        $this->assertSame(
            'NIH\\Router\\Tests\\Fixtures\\Controllers\\Blogs\\Threads\\ViewGet',
            $match->class,
        );
        $this->assertSame(['blogId' => '25'], $match->routeParams);
        $this->assertSame('/blogs/25/threads/view', $generator->generatePath('/blogs/threads/view', ['blogId' => 25]));
    }
}
