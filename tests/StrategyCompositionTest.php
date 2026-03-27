<?php

declare(strict_types=1);

namespace NIH\Router\Tests;

use NIH\Router\RouteMatcher;
use NIH\Router\RouterConfig;
use NIH\Router\Strategy\Consumer\PathTemplateConsumer;
use NIH\Router\Strategy\Consumer\SegmentIdConsumer;
use NIH\Router\Strategy\Consumer\SegmentSlugConsumer;
use NIH\Router\Strategy\Consumer\SegmentSlugIdConsumer;
use NIH\Router\Strategy\PathToClassStrategy;
use NIH\Router\UrlGenerator;
use PHPUnit\Framework\TestCase;

final class StrategyCompositionTest extends TestCase
{
    public function test_it_composes_path_template_and_path_to_class_strategies_for_single_segment_variables(): void
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
        $this->assertSame(
            '/blogs/25/threads/view',
            $generator->generatePath('/blogs/threads/view', ['blogId' => 25]),
        );
    }

    public function test_it_composes_title_id_and_path_to_class_strategies(): void
    {
        $config = new RouterConfig();
        $config->path('/articles/')
            ->strategy(SegmentSlugIdConsumer::class, [
                'id' => 'articleId',
                'title' => 'title',
            ])
            ->path('/comments/')
            ->strategy(PathToClassStrategy::class, [
                'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Articles\\Comments',
            ]);

        $matcher = new RouteMatcher($config);
        $generator = new UrlGenerator($config);
        $match = $matcher->match('/ARTICLES/SOME_TEXT.1234/COMMENTS/VIEW', 'GET');

        $this->assertSame(RouteMatcher::FOUND, $match->status);
        $this->assertSame(
            'NIH\\Router\\Tests\\Fixtures\\Controllers\\Articles\\Comments\\ViewGet',
            $match->class,
        );
        $this->assertSame(
            [
                'articleId' => 1234,
                'title' => 'some_text',
            ],
            $match->routeParams,
        );
        $this->assertSame(
            '/articles/some_text.1234/comments/view',
            $generator->generatePath('/articles/comments/view', [
                'title' => 'some_text',
                'articleId' => 1234,
            ]),
        );
    }

    public function test_it_composes_segment_int_and_path_to_class_strategies(): void
    {
        $config = new RouterConfig();
        $config->path('/blogs/')
            ->strategy(SegmentIdConsumer::class, [
                'param' => 'blogId',
            ])
            ->path('/threads/')
            ->strategy(PathToClassStrategy::class, [
                'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Blogs\\Threads',
            ]);

        $matcher = new RouteMatcher($config);
        $generator = new UrlGenerator($config);
        $match = $matcher->match('/BLOGS/25/THREADS/VIEW', 'GET');

        $this->assertSame(RouteMatcher::FOUND, $match->status);
        $this->assertSame(
            'NIH\\Router\\Tests\\Fixtures\\Controllers\\Blogs\\Threads\\ViewGet',
            $match->class,
        );
        $this->assertSame(['blogId' => '25'], $match->routeParams);
        $this->assertSame(
            '/blogs/25/threads/view',
            $generator->generatePath('/blogs/threads/view', [
                'blogId' => '25',
            ]),
        );
    }

    public function test_it_composes_segment_string_and_path_to_class_strategies(): void
    {
        $config = new RouterConfig();
        $config->path('/users/')
            ->strategy(SegmentSlugConsumer::class, [
                'param' => 'username',
            ])
            ->path('/profile/')
            ->strategy(PathToClassStrategy::class, [
                'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Users\\Profile',
            ]);

        $matcher = new RouteMatcher($config);
        $generator = new UrlGenerator($config);
        $match = $matcher->match('/USERS/ALICE/PROFILE/VIEW', 'GET');

        $this->assertSame(RouteMatcher::FOUND, $match->status);
        $this->assertSame(
            'NIH\\Router\\Tests\\Fixtures\\Controllers\\Users\\Profile\\ViewGet',
            $match->class,
        );
        $this->assertSame(['username' => 'alice'], $match->routeParams);
        $this->assertSame(
            '/users/alice/profile/view',
            $generator->generatePath('/users/profile/view', [
                'username' => 'alice',
            ]),
        );
    }

    public function test_it_composes_path_template_and_path_to_class_strategies(): void
    {
        $config = new RouterConfig();
        $config->path('/articles/')
            ->strategy(PathTemplateConsumer::class, [
                'pattern' => '{title}.{articleId:int}/',
            ])
            ->path('/comments/')
            ->strategy(PathToClassStrategy::class, [
                'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Articles\\Comments',
            ]);

        $matcher = new RouteMatcher($config);
        $generator = new UrlGenerator($config);
        $match = $matcher->match('/ARTICLES/SOME_TEXT.1234/COMMENTS/VIEW', 'GET');

        $this->assertSame(RouteMatcher::FOUND, $match->status);
        $this->assertSame(
            'NIH\\Router\\Tests\\Fixtures\\Controllers\\Articles\\Comments\\ViewGet',
            $match->class,
        );
        $this->assertSame(
            [
                'title' => 'some_text',
                'articleId' => '1234',
            ],
            $match->routeParams,
        );
        $this->assertSame(
            '/articles/some_text.1234/comments/view',
            $generator->generatePath('/articles/comments/view', [
                'title' => 'some_text',
                'articleId' => 1234,
            ]),
        );
    }

}
