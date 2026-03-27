<?php

declare(strict_types=1);

namespace NIH\Router\Tests;

use NIH\Router\Strategy\Consumer\PathTemplateConsumer;
use NIH\Router\RouteMatcher;
use NIH\Router\UrlGenerator;
use NIH\Router\RouterConfig;
use NIH\Router\Strategy\Site\SubdomainReaderSiteStrategy;
use NIH\Router\Strategy\PathToClassStrategy;
use NIH\Router\Tests\Fixtures\SiteStrategies\TenantSubdomainSiteStrategy;
use NIH\Router\Tests\Fixtures\Strategies\RewritePathStrategy;
use NIH\Router\Tests\Fixtures\Strategies\StaticPathStrategy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RouteMatcherUrlGeneratorRuntimeTest extends TestCase
{
    public function test_runtime_matches_an_invokable_controller(): void
    {
        [$matcher, $generator] = $this->createRuntime($this->buildConfig());

        $result = $matcher->match('/pub/forums/list/view', 'GET');

        $this->assertSame(RouteMatcher::FOUND, $result->status);
        $this->assertSame('NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums\\List\\ViewGet', $result->class);
        $this->assertSame('__invoke', $result->method);
    }

    public function test_runtime_returns_not_found_when_method_specific_class_is_missing(): void
    {
        [$matcher, $generator] = $this->createRuntime($this->buildConfig());

        $result = $matcher->match('/pub/users/', 'POST');

        $this->assertSame(RouteMatcher::NOT_FOUND, $result->status);
    }

    public function test_runtime_returns_not_found_when_no_node_matches(): void
    {
        [$matcher, $generator] = $this->createRuntime($this->buildConfig());

        $result = $matcher->match('/missing/path', 'GET');

        $this->assertSame(RouteMatcher::NOT_FOUND, $result->status);
    }

    public function test_runtime_generates_path_and_url(): void
    {
        [$matcher, $generator] = $this->createRuntime($this->buildConfig());

        $this->assertSame('/pub/forums/list/view', $generator->generatePath('/pub/forums/list/view'));
        $this->assertSame(
            'https://example.com/pub/forums/list/view?page=2#top',
            $generator->generateUrl('/pub/forums/list/view', ['page' => 2], fragment: 'top')
        );
        $this->assertSame(
            'https://alias.example.com/pub/forums/list/view?page=2',
            $generator->generateUrl('/pub/forums/list/view', ['page' => 2], 'https://alias.example.com')
        );
    }

    public function test_runtime_always_lowercases_runtime_paths(): void
    {
        [$matcher, $generator] = $this->createRuntime($this->buildConfig());

        $result = $matcher->match('/PUB/Forums/List/View', 'GET');

        $this->assertSame(RouteMatcher::FOUND, $result->status);
        $this->assertSame('/pub/forums/list/view', $generator->generatePath('/PUB/Forums/List/View'));
    }

    public function test_runtime_default_strategy_accepts_mixed_case_http_method(): void
    {
        [$matcher, $generator] = $this->createRuntime($this->buildConfig());

        $result = $matcher->match('/pub/forums/list/view', 'gEt');

        $this->assertSame(RouteMatcher::FOUND, $result->status);
        $this->assertSame('NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums\\List\\ViewGet', $result->class);
        $this->assertSame('__invoke', $result->method);
    }

    public function test_runtime_default_strategy_distinguishes_trailing_slash_routes(): void
    {
        [$matcher, $generator] = $this->createRuntime($this->buildConfig());

        $withoutSlash = $matcher->match('/pub/forums/list', 'GET');
        $withSlash = $matcher->match('/pub/forums/list/', 'GET');

        $this->assertSame(RouteMatcher::FOUND, $withoutSlash->status);
        $this->assertSame('NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums\\ListGet', $withoutSlash->class);
        $this->assertSame('__invoke', $withoutSlash->method);

        $this->assertSame(RouteMatcher::FOUND, $withSlash->status);
        $this->assertSame('NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums\\List\\Get', $withSlash->class);
        $this->assertSame('__invoke', $withSlash->method);

        $this->assertSame('/pub/forums/list', $generator->generatePath('/pub/forums/list'));
        $this->assertSame('/pub/forums/list/', $generator->generatePath('/pub/forums/list/'));
    }

    public function test_runtime_can_match_a_branch_with_an_internal_empty_segment(): void
    {
        $config = new RouterConfig();
        $config->site('https://example.com');
        $config->path('/pub//forums/')->strategy(PathToClassStrategy::class, [
            'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums',
        ]);

        [$matcher, $generator] = $this->createRuntime($config);
        $result = $matcher->match('/PUB//Forums/', 'GET');

        $this->assertSame(RouteMatcher::FOUND, $result->status);
        $this->assertSame('NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums\\Get', $result->class);
        $this->assertSame('__invoke', $result->method);
    }

    public function test_runtime_removes_only_one_leading_slash_during_matching(): void
    {
        $config = new RouterConfig();
        $config->site('https://example.com');
        $config->path('//pub/forums/')->strategy(PathToClassStrategy::class, [
            'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums',
        ]);

        [$matcher, $generator] = $this->createRuntime($config);
        $result = $matcher->match('//PUB/Forums/', 'GET');

        $this->assertSame(RouteMatcher::FOUND, $result->status);
        $this->assertSame('NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums\\Get', $result->class);
    }

    public function test_runtime_can_generate_a_branch_with_an_internal_empty_segment(): void
    {
        $config = new RouterConfig();
        $config->site('https://example.com');
        $config->path('/pub//forums/')->strategy(PathToClassStrategy::class, [
            'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums',
        ]);

        [$matcher, $generator] = $this->createRuntime($config);

        $this->assertSame('/pub//forums/', $generator->generatePath('/PUB//Forums/'));
        $this->assertSame('/pub//forums/list/view', $generator->generatePath('/PUB//Forums/List/View'));
        $this->assertSame(
            'https://example.com/pub//forums/',
            $generator->generateUrl('/PUB//Forums/'),
        );
        $this->assertSame(
            'https://example.com/pub//forums/list/view',
            $generator->generateUrl('/PUB//Forums/List/View'),
        );
    }

    public function test_runtime_removes_only_one_leading_slash_during_generation(): void
    {
        $config = new RouterConfig();
        $config->site('https://example.com');
        $config->path('//pub/forums/')->strategy(PathToClassStrategy::class, [
            'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums',
        ]);

        [$matcher, $generator] = $this->createRuntime($config);

        $this->assertSame('//pub/forums/', $generator->generatePath('//PUB/Forums/'));
        $this->assertSame('//pub/forums/list/view', $generator->generatePath('//PUB/Forums/List/View'));
        $this->assertSame(
            'https://example.com//pub/forums/',
            $generator->generateUrl('//PUB/Forums/'),
        );
        $this->assertSame(
            'https://example.com//pub/forums/list/view',
            $generator->generateUrl('//PUB/Forums/List/View'),
        );
    }

    public function test_runtime_can_build_an_empty_segment_from_an_empty_child_prefix(): void
    {
        $config = new RouterConfig();
        $config->site('https://example.com');
        $config->path('/pub')
            ->path('')
            ->path('forums/')
            ->strategy(PathToClassStrategy::class, [
                'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums',
            ]);

        [$matcher, $generator] = $this->createRuntime($config);

        $this->assertSame(RouteMatcher::FOUND, $matcher->match('/pub//forums/', 'GET')->status);
        $this->assertSame('/pub//forums/', $generator->generatePath('/pub//forums/'));
    }

    public function test_runtime_can_configure_root_node_via_explicit_root_path(): void
    {
        $config = new RouterConfig();
        $config->site('https://example.com');
        $config->path()->strategy(StaticPathStrategy::class, [
            'path' => '/ping',
            'class' => 'Root\\PingAction',
            'method' => 'GET',
            'allowedMethods' => ['GET'],
        ]);

        [$matcher, $generator] = $this->createRuntime($config);
        $match = $matcher->match('/ping', 'GET');

        $this->assertSame(RouteMatcher::FOUND, $match->status);
        $this->assertSame('Root\\PingAction', $match->class);
        $this->assertSame('/ping', $generator->generatePath('/ping'));
    }

    public function test_runtime_can_generate_root_path_and_url(): void
    {
        $config = new RouterConfig();
        $config->site('https://example.com');
        $config->path()->action('', 'Root\\HomeAction', '__invoke', ['GET']);

        [$matcher, $generator] = $this->createRuntime($config);
        $match = $matcher->match('/', 'GET');

        $this->assertSame(RouteMatcher::FOUND, $match->status);
        $this->assertSame('Root\\HomeAction', $match->class);
        $this->assertSame('/', $generator->generatePath('/'));
        $this->assertSame('https://example.com/', $generator->generateUrl('/'));
    }

    public function test_runtime_can_match_and_generate_an_exact_double_slash_root_route(): void
    {
        $config = new RouterConfig();
        $config->site('https://example.com');
        $config->path('/')->action('/', 'Root\\DoubleSlashAction', '__invoke', ['GET']);

        [$matcher, $generator] = $this->createRuntime($config);
        $doubleSlashMatch = $matcher->match('//', 'GET');
        $singleSlashMatch = $matcher->match('/', 'GET');

        $this->assertSame(RouteMatcher::FOUND, $doubleSlashMatch->status);
        $this->assertSame('Root\\DoubleSlashAction', $doubleSlashMatch->class);
        $this->assertSame(RouteMatcher::NOT_FOUND, $singleSlashMatch->status);
        $this->assertSame('//', $generator->generatePath('//'));
        $this->assertSame('', $generator->generatePath('/'));
        $this->assertSame('https://example.com//', $generator->generateUrl('//'));
        $this->assertSame('', $generator->generateUrl('/'));
    }

    public function test_builder_action_can_distinguish_exact_remainder_paths_on_the_same_node(): void
    {
        $config = new RouterConfig();
        $config->site('https://example.com');
        $config->path('/health')->action('', 'App\\Controller\\HealthAction', '__invoke', ['GET']);
        $config->path('/health')->action('/', 'App\\Controller\\HealthSlashAction', '__invoke', ['GET']);
        $config->path('/health')->action('/check/', 'App\\Controller\\HealthCheckSlashAction', '__invoke', ['GET']);

        [$matcher, $generator] = $this->createRuntime($config);

        $get = $matcher->match('/health', 'GET');
        $getWithSlash = $matcher->match('/health/', 'GET');
        $getChildWithSlash = $matcher->match('/health/check/', 'GET');
        $post = $matcher->match('/health/', 'POST');

        $this->assertSame(RouteMatcher::FOUND, $get->status);
        $this->assertSame('App\\Controller\\HealthAction', $get->class);
        $this->assertSame('__invoke', $get->method);

        $this->assertSame(RouteMatcher::FOUND, $getWithSlash->status);
        $this->assertSame('App\\Controller\\HealthSlashAction', $getWithSlash->class);

        $this->assertSame(RouteMatcher::FOUND, $getChildWithSlash->status);
        $this->assertSame('App\\Controller\\HealthCheckSlashAction', $getChildWithSlash->class);

        $this->assertSame(RouteMatcher::METHOD_NOT_ALLOWED, $post->status);
        $this->assertSame(['GET'], $post->allowedMethods);

        $this->assertSame('/health', $generator->generatePath('/health'));
        $this->assertSame('/health/', $generator->generatePath('/health/'));
        $this->assertSame('/health/check/', $generator->generatePath('/health/check/'));
        $this->assertSame('https://example.com/health', $generator->generateUrl('/health'));
        $this->assertSame('https://example.com/health/', $generator->generateUrl('/health/'));
        $this->assertSame('https://example.com/health/check/', $generator->generateUrl('/health/check/'));
    }

    public function test_runtime_url_requires_a_named_default_site_or_an_explicit_site(): void
    {
        $config = new RouterConfig();
        $config->path('/pub/forums/')->strategy(PathToClassStrategy::class, [
            'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums',
        ]);

        [$matcher, $generator] = $this->createRuntime($config);

        $this->assertSame('', $generator->generateUrl('/pub/forums/list/view'));

        $this->expectException(RuntimeException::class);
        $generator->generateUrl('/pub/forums/list/view', throwOnError: true);
    }

    public function test_runtime_path_returns_empty_string_by_default_when_generation_fails(): void
    {
        [$matcher, $generator] = $this->createRuntime($this->buildConfig());

        $this->assertSame('', $generator->generatePath('/missing/path'));

        $this->expectException(RuntimeException::class);
        $generator->generatePath('/missing/path', throwOnError: true);
    }

    public function test_runtime_uses_non_default_strategy_via_strategy_interface(): void
    {
        $config = new RouterConfig();
        $config->site('https://example.com');
        $config->path('/custom/')->strategy(StaticPathStrategy::class, [
            'path' => '/ping',
            'class' => 'Custom\\PingAction',
            'method' => 'GET',
            'allowedMethods' => ['GET'],
        ]);

        [$matcher, $generator] = $this->createRuntime($config);
        $match = $matcher->match('/custom/ping', 'GET');

        $this->assertSame(RouteMatcher::FOUND, $match->status);
        $this->assertSame('Custom\\PingAction', $match->class);
        $this->assertSame('get', $match->method);
        $this->assertSame('/custom/ping', $generator->generatePath('/custom/ping'));
    }

    public function test_runtime_can_reuse_a_prebuilt_strategy_object_on_multiple_branches(): void
    {
        $config = new RouterConfig();
        $config->site('https://example.com');

        $strategy = new StaticPathStrategy(
            '/ping',
            'Custom\\PingAction',
            'GET',
            ['GET'],
        );

        $config->path('/custom/')->strategy($strategy);
        $config->path('/custom-copy/')->strategy($strategy);

        [$matcher, $generator] = $this->createRuntime($config);

        $custom = $matcher->match('/custom/ping', 'GET');
        $copy = $matcher->match('/custom-copy/ping', 'GET');

        $this->assertSame(RouteMatcher::FOUND, $custom->status);
        $this->assertSame('Custom\\PingAction', $custom->class);
        $this->assertSame(RouteMatcher::FOUND, $copy->status);
        $this->assertSame('Custom\\PingAction', $copy->class);
        $this->assertSame('/custom/ping', $generator->generatePath('/custom/ping'));
        $this->assertSame('/custom-copy/ping', $generator->generatePath('/custom-copy/ping'));
    }

    public function test_runtime_collects_allowed_methods_from_custom_strategy(): void
    {
        $config = new RouterConfig();
        $config->site('https://example.com');
        $config->path('/custom/')->strategy(StaticPathStrategy::class, [
            'path' => '/ping',
            'class' => 'Custom\\PingAction',
            'method' => 'GET',
            'allowedMethods' => ['GET'],
        ]);

        [$matcher, $generator] = $this->createRuntime($config);
        $match = $matcher->match('/custom/ping', 'POST');

        $this->assertSame(RouteMatcher::METHOD_NOT_ALLOWED, $match->status);
        $this->assertSame(['GET'], $match->allowedMethods);
    }

    public function test_runtime_can_consume_a_variable_segment_and_continue_to_children(): void
    {
        $config = new RouterConfig();
        $config->site('https://example.com');
        $config->path('/blogs/')
            ->strategy(PathTemplateConsumer::class, [
                'pattern' => '{blogId:int}/',
            ])
            ->path('/threads/')
            ->strategy(PathToClassStrategy::class, [
                'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Blogs\\Threads',
            ]);

        [$matcher, $generator] = $this->createRuntime($config);
        $result = $matcher->match('/BLOGS/25/THREADS/VIEW', 'GET');

        $this->assertSame(RouteMatcher::FOUND, $result->status);
        $this->assertSame('NIH\\Router\\Tests\\Fixtures\\Controllers\\Blogs\\Threads\\ViewGet', $result->class);
        $this->assertSame(['blogId' => '25'], $result->routeParams);
        $this->assertSame('/blogs/25/threads/view', $generator->generatePath('/blogs/threads/view', ['blogId' => 25]));
        $this->assertSame(
            'https://example.com/blogs/25/threads/view?page=2',
            $generator->generateUrl('/blogs/threads/view', ['blogId' => 25, 'page' => 2]),
        );
    }

    public function test_runtime_final_generation_can_use_modified_path_without_fragment_changes(): void
    {
        $config = new RouterConfig();
        $config->site('https://example.com');
        $config->path('/pub/rewrite/')->strategy(RewritePathStrategy::class, [
            'from' => 'alias',
            'to' => 'articles/view',
        ]);

        [$matcher, $generator] = $this->createRuntime($config);

        $this->assertSame('/pub/rewrite/articles/view', $generator->generatePath('/pub/rewrite/alias'));
    }

    public function test_runtime_final_generation_can_request_a_trailing_slash_via_path_sentinel(): void
    {
        $config = new RouterConfig();
        $config->site('https://example.com');
        $config->path('/pub/rewrite/')->strategy(RewritePathStrategy::class, [
            'from' => 'alias',
            'to' => '/',
        ]);

        [$matcher, $generator] = $this->createRuntime($config);

        $this->assertSame('/pub/rewrite/', $generator->generatePath('/pub/rewrite/alias'));
    }

    public function test_runtime_can_match_independent_site_trees(): void
    {
        $config = new RouterConfig();

        $config->site('https://www.example.com')
            ->path('/pub/forums/')
            ->strategy(PathToClassStrategy::class, [
                'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums',
            ]);

        $config->site('https://api.example.com')
            ->path('/v1/')
            ->strategy(StaticPathStrategy::class, [
                'path' => '/ping',
                'class' => 'Api\\PingAction',
                'method' => 'GET',
                'allowedMethods' => ['GET'],
            ]);

        [$matcher, $generator] = $this->createRuntime($config);

        $this->assertSame(RouteMatcher::FOUND, $matcher->match('/v1/ping', 'GET', 'https://api.example.com')->status);
        $this->assertSame(RouteMatcher::NOT_FOUND, $matcher->match('/v1/ping', 'GET', 'https://www.example.com')->status);
        $this->assertSame(
            'https://api.example.com/v1/ping',
            $generator->generateUrl('/v1/ping', site: 'https://api.example.com'),
        );
    }

    public function test_runtime_can_match_site_aliases_without_copying_the_tree(): void
    {
        $config = $this->buildConfig();
        [$matcher, $generator] = $this->createRuntime($config);

        $result = $matcher->match('/pub/forums/list/view', 'GET', 'https://foo.example.com');

        $this->assertSame(RouteMatcher::FOUND, $result->status);
        $this->assertSame(
            'https://foo.example.com/pub/forums/list/view?page=2',
            $generator->generateUrl('/pub/forums/list/view', ['page' => 2], 'https://foo.example.com'),
        );
    }

    public function test_exact_alias_can_override_an_existing_site_string(): void
    {
        $config = new RouterConfig();

        $config->site('https://www.example.com')
            ->path('/pub/forums/')
            ->strategy(PathToClassStrategy::class, [
                'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums',
            ]);

        $config->site('https://api.example.com')
            ->path('/v1/')
            ->strategy(StaticPathStrategy::class, [
                'path' => '/ping',
                'class' => 'Api\\PingAction',
                'method' => 'GET',
                'allowedMethods' => ['GET'],
            ]);

        $config->site('https://api.example.com')->alias('https://www.example.com');

        [$matcher, $generator] = $this->createRuntime($config);

        $this->assertSame(RouteMatcher::FOUND, $matcher->match('/v1/ping', 'GET', 'https://www.example.com')->status);
        $this->assertSame(RouteMatcher::NOT_FOUND, $matcher->match('/pub/forums/list/view', 'GET', 'https://www.example.com')->status);
        $this->assertSame('https://www.example.com/v1/ping', $generator->generateUrl('/v1/ping', site: 'https://www.example.com'));
    }

    public function test_site_strategy_can_extract_params_before_path_matching(): void
    {
        $config = new RouterConfig();
        $config->site('https://www.example.com')
            ->wildcardAlias('https://*.example.com')
            ->siteStrategy(TenantSubdomainSiteStrategy::class, [
                'param' => 'tenant',
                'canonical' => 'https://www.example.com',
                'suffix' => '.example.com',
            ])
            ->path('/pub/forums/')
            ->strategy(PathToClassStrategy::class, [
                'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums',
            ]);

        [$matcher, $generator] = $this->createRuntime($config);
        $match = $matcher->match('/pub/forums/list/view', 'GET', 'https://acme.example.com');

        $this->assertSame(RouteMatcher::FOUND, $match->status);
        $this->assertSame('acme', $match->routeParams['tenant']);
        $this->assertSame(
            'https://acme.example.com/pub/forums/list/view',
            $generator->generateUrl('/pub/forums/list/view', ['tenant' => 'acme']),
        );
    }

    public function test_runtime_can_reuse_a_prebuilt_site_strategy_object(): void
    {
        $config = new RouterConfig();

        $strategy = new TenantSubdomainSiteStrategy(
            param: 'tenant',
            canonical: 'https://www.example.com',
            suffix: '.example.com',
        );

        $config->site('https://www.example.com')
            ->wildcardAlias('https://*.example.com')
            ->siteStrategy($strategy)
            ->path('/pub/forums/')
            ->strategy(PathToClassStrategy::class, [
                'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums',
            ]);

        [$matcher, $generator] = $this->createRuntime($config);
        $match = $matcher->match('/pub/forums/list/view', 'GET', 'https://acme.example.com');

        $this->assertSame(RouteMatcher::FOUND, $match->status);
        $this->assertSame('acme', $match->routeParams['tenant']);
        $this->assertSame(
            'https://acme.example.com/pub/forums/list/view',
            $generator->generateUrl('/pub/forums/list/view', ['tenant' => 'acme']),
        );
    }

    public function test_builtin_site_strategy_only_reads_the_subdomain(): void
    {
        $config = new RouterConfig();
        $config->site('https://www.example.com')
            ->wildcardAlias('https://*.example.com')
            ->siteStrategy(SubdomainReaderSiteStrategy::class, [
                'param' => 'tenant',
            ])
            ->path('/pub/forums/')
            ->strategy(PathToClassStrategy::class, [
                'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums',
            ]);

        [$matcher, $generator] = $this->createRuntime($config);
        $match = $matcher->match('/pub/forums/list/view', 'GET', 'https://acme.example.com');

        $this->assertSame(RouteMatcher::FOUND, $match->status);
        $this->assertSame('acme', $match->routeParams['tenant']);
        $this->assertSame(
            'https://www.example.com/pub/forums/list/view?tenant=acme',
            $generator->generateUrl('/pub/forums/list/view', ['tenant' => 'acme']),
        );
    }

    public function test_builtin_site_strategy_uses_subdomain_as_the_default_param_name(): void
    {
        $config = new RouterConfig();
        $config->site('https://www.example.com')
            ->wildcardAlias('https://*.example.com')
            ->siteStrategy(SubdomainReaderSiteStrategy::class)
            ->path('/pub/forums/')
            ->strategy(PathToClassStrategy::class, [
                'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums',
            ]);

        [$matcher, $generator] = $this->createRuntime($config);
        $match = $matcher->match('/pub/forums/list/view', 'GET', 'https://acme.example.com');

        $this->assertSame(RouteMatcher::FOUND, $match->status);
        $this->assertSame('acme', $match->routeParams['subdomain']);
    }

    public function test_builtin_site_strategy_treats_subdomain_as_optional_by_default(): void
    {
        $config = new RouterConfig();
        $config->site('https://example.com')
            ->siteStrategy(SubdomainReaderSiteStrategy::class)
            ->path('/pub/forums/')
            ->strategy(PathToClassStrategy::class, [
                'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums',
            ]);

        [$matcher, $generator] = $this->createRuntime($config);
        $match = $matcher->match('/pub/forums/list/view', 'GET', 'https://example.com');

        $this->assertSame(RouteMatcher::FOUND, $match->status);
        $this->assertArrayNotHasKey('subdomain', $match->routeParams);
    }

    public function test_builtin_site_strategy_ignores_invalid_site_strings_when_subdomain_is_optional(): void
    {
        $config = new RouterConfig();
        $config->site('https://example.com')
            ->alias('garbage')
            ->siteStrategy(SubdomainReaderSiteStrategy::class)
            ->path('/pub/forums/')
            ->strategy(PathToClassStrategy::class, [
                'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums',
            ]);

        [$matcher, $generator] = $this->createRuntime($config);
        $match = $matcher->match('/pub/forums/list/view', 'GET', 'garbage');

        $this->assertSame(RouteMatcher::FOUND, $match->status);
        $this->assertArrayNotHasKey('subdomain', $match->routeParams);
    }

    public function test_builtin_site_strategy_can_require_a_subdomain(): void
    {
        $config = new RouterConfig();
        $config->site('https://example.com')
            ->siteStrategy(SubdomainReaderSiteStrategy::class, [
                'required' => true,
            ])
            ->path('/pub/forums/')
            ->strategy(PathToClassStrategy::class, [
                'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums',
            ]);

        [$matcher, $generator] = $this->createRuntime($config);

        $this->assertSame(RouteMatcher::NOT_FOUND, $matcher->match('/pub/forums/list/view', 'GET', 'https://example.com')->status);
    }

    public function test_runtime_skips_site_strategies_when_site_is_not_provided(): void
    {
        $config = new RouterConfig();
        $config->site('https://example.com')
            ->siteStrategy(SubdomainReaderSiteStrategy::class, [
                'required' => true,
            ])
            ->path('/pub/forums/')
            ->strategy(PathToClassStrategy::class, [
                'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums',
            ]);

        [$matcher, $generator] = $this->createRuntime($config);
        $match = $matcher->match('/pub/forums/list/view', 'GET');

        $this->assertSame(RouteMatcher::FOUND, $match->status);
        $this->assertArrayNotHasKey('subdomain', $match->routeParams);
    }

    public function test_builtin_site_strategy_rejects_invalid_site_strings_when_subdomain_is_required(): void
    {
        $config = new RouterConfig();
        $config->site('https://example.com')
            ->alias('garbage')
            ->siteStrategy(SubdomainReaderSiteStrategy::class, [
                'required' => true,
            ])
            ->path('/pub/forums/')
            ->strategy(PathToClassStrategy::class, [
                'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums',
            ]);

        [$matcher, $generator] = $this->createRuntime($config);

        $this->assertSame(RouteMatcher::NOT_FOUND, $matcher->match('/pub/forums/list/view', 'GET', 'garbage')->status);
    }

    /**
     * @return array{0: RouteMatcher, 1: UrlGenerator}
     */
    private function createRuntime(RouterConfig $config): array
    {
        return [
            new RouteMatcher($config),
            new UrlGenerator($config),
        ];
    }

    private function buildConfig(): RouterConfig
    {
        $config = new RouterConfig();
        $config->site('https://example.com')
            ->alias('https://alias.example.com')
            ->wildcardAlias('https://*.example.com');

        $config->path('/pub/forums/')->strategy(PathToClassStrategy::class, [
            'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums',
        ]);

        $config->path('/pub/users/')->strategy(PathToClassStrategy::class, [
            'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Users',
        ]);

        return $config;
    }
}
