<?php

declare(strict_types=1);

namespace NIH\Router\Tests;

use NIH\Router\RouterConfig;
use NIH\Router\RouterData;
use NIH\Router\Strategy\PathToClassStrategy;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class RouterConfigTest extends TestCase
{
    public function test_it_builds_a_shared_lowercased_prefix_tree_in_any_order(): void
    {
        $config = new RouterConfig();

        $config->path('/pub/users/')->strategy(PathToClassStrategy::class, [
            'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Users',
        ]);

        $config->path('/PUB')->middleware('AuthMiddleware');

        $tree = $this->data($config)['sites']['__default__']['root'];

        self::assertArrayHasKey('pub', $tree['children']);
        self::assertArrayNotHasKey('prefix', $tree['children']['pub']);
        self::assertCount(1, $tree['children']['pub']['middlewares']);
        self::assertArrayHasKey('users', $tree['children']['pub']['children']);
        self::assertArrayNotHasKey('prefix', $tree['children']['pub']['children']['users']);
        self::assertCount(1, $tree['children']['pub']['children']['users']['strategies']);
    }

    public function test_it_resolves_relative_child_paths_from_the_current_builder(): void
    {
        $config = new RouterConfig();

        $config->path('/PUB')
            ->path('Forums')
            ->path('List')
            ->strategy(PathToClassStrategy::class, [
                'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums\\List',
            ]);

        $tree = $this->data($config)['sites']['__default__']['root'];

        self::assertArrayHasKey('list', $tree['children']['pub']['children']['forums']['children']);
        self::assertArrayNotHasKey('prefix', $tree['children']['pub']['children']['forums']['children']['list']);
    }

    public function test_it_always_lowercases_configured_paths(): void
    {
        $config = new RouterConfig();
        $config->path('/PUB')->middleware('AuthMiddleware');

        $tree = $this->data($config)['sites']['__default__']['root'];

        self::assertArrayHasKey('pub', $tree['children']);
        self::assertArrayNotHasKey('PUB', $tree['children']);
    }

    public function test_it_stores_middleware_instances_directly_in_the_tree(): void
    {
        $config = new RouterConfig();
        $middleware = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };
        $config->path('/pub')->middleware($middleware);

        $tree = $this->data($config)['sites']['__default__']['root'];

        self::assertSame($middleware, $tree['children']['pub']['middlewares'][0]);
    }

    public function test_it_stores_middleware_class_strings_directly_in_the_tree(): void
    {
        $config = new RouterConfig();
        $config->path('/pub')->middleware('AuthMiddleware');

        $tree = $this->data($config)['sites']['__default__']['root'];

        self::assertSame('AuthMiddleware', $tree['children']['pub']['middlewares'][0]);
    }

    public function test_it_preserves_internal_empty_segments_in_configured_prefixes(): void
    {
        $config = new RouterConfig();
        $config->path('/PUB//Forums/')->middleware('EmptySegmentMiddleware');

        $tree = $this->data($config)['sites']['__default__']['root'];

        self::assertArrayHasKey('pub', $tree['children']);
        self::assertArrayHasKey('', $tree['children']['pub']['children']);
        self::assertArrayHasKey('forums', $tree['children']['pub']['children']['']['children']);
        self::assertCount(1, $tree['children']['pub']['children']['']['children']['forums']['middlewares']);
        self::assertArrayNotHasKey('', $tree['children']['pub']['children']['']['children']['forums']['children'] ?? []);
    }

    public function test_it_distinguishes_empty_and_slash_only_child_prefixes(): void
    {
        $config = new RouterConfig();
        $config->path('/pub')->path()->middleware('PubMiddleware');
        $config->path('/pub')->path('')->middleware('EmptySegmentMiddleware');
        $config->path('/pub')->path('/')->strategy(PathToClassStrategy::class, [
            'namespace' => 'NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums',
        ]);

        $tree = $this->data($config)['sites']['__default__']['root'];

        self::assertArrayHasKey('', $tree['children']['pub']['children']);
        self::assertCount(1, $tree['children']['pub']['middlewares']);
        self::assertCount(1, $tree['children']['pub']['children']['']['middlewares']);
        self::assertArrayNotHasKey('strategies', $tree['children']['pub']['children']['']);
        self::assertCount(1, $tree['children']['pub']['strategies']);
    }

    public function test_root_level_empty_and_slash_paths_have_different_meaning(): void
    {
        $config = new RouterConfig();
        $config->path('')->middleware('RootEmptySegmentMiddleware');
        $config->path('/')->middleware('RootMiddleware');
        $config->path()->middleware('ImplicitRootMiddleware');

        $tree = $this->data($config)['sites']['__default__']['root'];

        self::assertCount(2, $tree['middlewares']);
        self::assertArrayHasKey('', $tree['children']);
        self::assertCount(1, $tree['children']['']['middlewares']);
    }

    public function test_the_first_named_site_renames_the_anonymous_default_tree(): void
    {
        $config = new RouterConfig();
        $config->path('/pub')->middleware('PubMiddleware');
        $config->site('https://www.example.com')->wildcardAlias('https://*.example.com');

        $data = $this->data($config);

        self::assertSame('https://www.example.com', $data['defaultSiteKey']);
        self::assertArrayNotHasKey('__default__', $data['sites']);
        self::assertArrayHasKey('https://www.example.com', $data['sites']);
        self::assertSame('https://www.example.com', $data['aliases']['https://www.example.com']);
        self::assertArrayHasKey('pub', $data['sites']['https://www.example.com']['root']['children']);
        self::assertSame(
            'https://*.example.com',
            $data['wildcardAliases'][0]['pattern'],
        );
    }

    public function test_the_first_alias_renames_the_anonymous_default_tree_and_selects_the_site(): void
    {
        $config = new RouterConfig();

        $config->path('/pub')->middleware('PubMiddleware');
        $config->alias('https://www.example.com')->path('/api')->middleware('ApiMiddleware');

        $data = $this->data($config);

        self::assertSame('https://www.example.com', $data['defaultSiteKey']);
        self::assertArrayHasKey('https://www.example.com', $data['sites']);
        self::assertSame('https://www.example.com', $data['aliases']['https://www.example.com']);
        self::assertArrayHasKey('pub', $data['sites']['https://www.example.com']['root']['children']);
        self::assertArrayHasKey('api', $data['sites']['https://www.example.com']['root']['children']);
    }

    public function test_the_first_exact_wildcard_alias_renames_the_anonymous_default_tree_and_selects_the_site(): void
    {
        $config = new RouterConfig();

        $config->path('/pub')->middleware('PubMiddleware');
        $config->wildcardAlias('https://www.example.com')->path('/api')->middleware('ApiMiddleware');

        $data = $this->data($config);

        self::assertSame('https://www.example.com', $data['defaultSiteKey']);
        self::assertArrayHasKey('https://www.example.com', $data['sites']);
        self::assertSame('https://www.example.com', $data['aliases']['https://www.example.com']);
        self::assertArrayHasKey('pub', $data['sites']['https://www.example.com']['root']['children']);
        self::assertArrayHasKey('api', $data['sites']['https://www.example.com']['root']['children']);
        self::assertSame([], $data['wildcardAliases']);
    }

    public function test_the_first_wildcard_alias_requires_a_selected_exact_site(): void
    {
        $config = new RouterConfig();

        try {
            $config->wildcardAlias('https://*.example.com');
            self::fail('Expected wildcardAlias() to reject a wildcard pattern without a selected site.');
        } catch (RuntimeException $e) {
            self::assertSame(
                'wildcardAlias() with a wildcard pattern requires a selected site.',
                $e->getMessage(),
            );
        }

        $data = $this->data($config);

        self::assertSame('__default__', $data['defaultSiteKey']);
        self::assertSame([], $data['aliases']);
        self::assertSame([], $data['wildcardAliases']);
    }

    public function test_alias_keeps_wildcard_characters_as_a_literal_exact_alias(): void
    {
        $config = new RouterConfig();
        $config->site('https://www.example.com')->alias('https://*.example.com');

        $data = $this->data($config);

        self::assertSame('https://www.example.com', $data['aliases']['https://*.example.com']);
        self::assertSame([], $data['wildcardAliases']);
    }

    public function test_exact_alias_assignment_overwrites_the_previous_exact_target(): void
    {
        $config = new RouterConfig();
        $config->site('https://www.example.com');
        $config->site('https://api.example.com')->alias('https://www.example.com');

        $data = $this->data($config);

        self::assertSame('https://api.example.com', $data['aliases']['https://www.example.com']);
    }

    public function test_site_default_selector_always_targets_the_current_default_site(): void
    {
        $config = new RouterConfig();

        $config->site('https://www.example.com')->path('/main')->middleware('MainMiddleware');
        $config->site('https://api.example.com')->asDefault();
        $config->path('/health')->middleware('HealthMiddleware');

        $data = $this->data($config);

        self::assertSame('https://api.example.com', $data['defaultSiteKey']);
        self::assertArrayHasKey('health', $data['sites']['https://api.example.com']['root']['children']);
        self::assertArrayNotHasKey('health', $data['sites']['https://www.example.com']['root']['children'] ?? []);
        self::assertArrayHasKey('main', $data['sites']['https://www.example.com']['root']['children']);
    }

    public function test_site_level_empty_and_slash_paths_have_different_meaning(): void
    {
        $config = new RouterConfig();

        $config->site('https://www.example.com')->path('')->middleware('RootEmptySegmentMiddleware');
        $config->site('https://www.example.com')->path('/')->middleware('RootMiddleware');
        $config->site('https://www.example.com')->path()->middleware('ImplicitRootMiddleware');

        $data = $this->data($config);

        self::assertCount(2, $data['sites']['https://www.example.com']['root']['middlewares']);
        self::assertArrayHasKey('', $data['sites']['https://www.example.com']['root']['children']);
        self::assertCount(1, $data['sites']['https://www.example.com']['root']['children']['']['middlewares']);
    }

    /**
     * @return array{
     *     defaultSiteKey: string,
     *     aliases: array<string, string>,
     *     wildcardAliases: list<array{siteKey: string, pattern: string, matcher: array{type: 'exact'|'glob'|'regex', value: string}}>,
     *     sites: array<string, array{siteStrategies: list<array>, root: array}>
     * }
     */
    private function data(RouterConfig $config): array
    {
        $data = new class extends RouterData {
            public function read(RouterConfig $config): array
            {
                $this->sites = &$config->sites;
                $this->defaultSiteKey = &$config->defaultSiteKey;
                $this->aliases = &$config->aliases;
                $this->wildcardAliases = &$config->wildcardAliases;

                return [
                    'defaultSiteKey' => $this->defaultSiteKey,
                    'aliases' => $this->aliases,
                    'wildcardAliases' => $this->wildcardAliases,
                    'sites' => $this->sites,
                ];
            }
        };

        return $data->read($config);
    }
}
