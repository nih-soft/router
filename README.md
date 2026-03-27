# NIH Router

Tree-based, site-aware router configuration for PHP with convention-based matching, optional PSR-15 dispatch, and URL generation.

## Why Another Router

Routers like Symfony Router are excellent, but they are optimized around a compiled representation. If route configuration must be assembled dynamically at runtime and cannot be persisted, a classic short-lived PHP request pays that build cost again on every request.

There is another practical issue: in many popular routers every route is described explicitly, one by one. On large projects that gets repetitive fast. When whole branches follow predictable naming, a convention-based rule can be much easier to maintain than a long flat list of route declarations.

This package targets a different space:

- no separate compile step
- runtime-built configuration when needed
- tree/group-oriented configuration instead of route-by-route registration
- convention-based matching and generation for large route subtrees
- middleware scopes attached to any branch

## What It Is

- a tree-based configuration API centered around branches, not a flat explicit route registry
- a core runtime made of `RouteMatcher` and `UrlGenerator`
- an optional PSR-15 integration layer built on the same config tree

## When To Use It

This package fits best when:

- different modules need to extend the same path subtree independently
- large route branches follow naming conventions
- you want the same configuration to drive matching, generation, and dispatch

## Installation

```bash
composer require nih/router
```

## Minimal Example

```php
use NIH\Router\RouteMatcher;
use NIH\Router\RouterConfig;
use NIH\Router\Strategy\PathToClassStrategy;
use NIH\Router\UrlGenerator;

$config = new RouterConfig();

$config->site('https://example.com');

$config->path('/health')
    ->action('', App\Controller\HealthAction::class, '__invoke', ['GET']);

$config->path('/forums/')
    ->strategy(PathToClassStrategy::class, [
        'namespace' => 'App\\Controller\\Forums',
    ]);

$matcher = new RouteMatcher($config);
$generator = new UrlGenerator($config);

$health = $matcher->match('/health', 'GET');
$forum = $matcher->match('/forums/view', 'GET');

$path = $generator->generatePath('/forums/view');
$url = $generator->generateUrl('/forums/view', ['page' => 2]);
```

How to read this config:

- `path('/health')->action(...)` adds one exact terminal route
- `path('/forums/')->strategy(...)` attaches a rule to the whole `/forums/` subtree
- `PathToClassStrategy` resolves controller classes by path and HTTP method convention

Typical `PathToClassStrategy` mapping for namespace `App\\Controller\\Forums`:

- `GET /forums/` -> `App\\Controller\\Forums\\Get`
- `GET /forums/view` -> `App\\Controller\\Forums\\ViewGet`
- matched method is always `__invoke`

## Core Runtime API

`RouteMatcher::match()` returns a `RouteMatchResult` object with:

- `status`
- `class`
- `method`
- `routeParams`
- `queryParams`
- `allowedMethods`
- `middlewares`

Status constants live on `RouteMatcher`:

- `RouteMatcher::FOUND`
- `RouteMatcher::NOT_FOUND`
- `RouteMatcher::METHOD_NOT_ALLOWED`

## Important Notes

- `generatePath()` and `generateUrl()` work with the logical configured path. Consumer strategies may fill real URL segments from params during generation.
- Consumer strategies may consume either the whole current remainder path, such as `PathTemplateConsumer`, or just one next segment, such as the segment consumers.
- Configured prefixes, runtime paths, and runtime sites are lowercased immediately.
- Runtime matching does not collapse repeated slashes or rewrite backslashes.
- The core router works with strings and arrays. PSR-7 and PSR-15 live in the middleware layer.

## Documentation

- [docs/configuration.md](docs/configuration.md) - configuration model, `path()`, `action()`, strategies, middlewares, sites, aliases
- [docs/router.md](docs/router.md) - `RouteMatcher`, `UrlGenerator`, runtime rules, `RouteMatchResult`
- [docs/psr15-integration.md](docs/psr15-integration.md) - `RouteMatchMiddleware`, `RouteDispatchMiddleware`, dispatch attributes
