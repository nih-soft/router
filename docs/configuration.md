# Configuration

`RouterConfig` is the configuration entry point.

This library is branch-oriented. The main idea is to configure a subtree once and let a strategy cover many concrete routes, instead of describing every route one by one in a long flat list.

That matters in two common cases:

- different modules need to extend the same branch independently
- large route branches follow a naming convention and do not need explicit route-by-route declarations

## Mental Model

Think about configuration in layers:

- `site(...)` selects which site tree you are editing
- `path(...)` moves to a branch inside that tree
- `middleware(...)` attaches middleware to the current branch
- `strategy(...)` attaches a matching/generation rule to the current branch
- `action(...)` adds one exact terminal action on the current branch

Small example:

```php
use NIH\Router\RouterConfig;
use NIH\Router\Strategy\PathToClassStrategy;

$config = new RouterConfig();

// Module A
$config->path('/pub')->middleware('AuthMiddleware');

// Module B
$config->path('/pub/forums/')
    ->strategy(PathToClassStrategy::class, [
        'namespace' => 'App\\Controller\\Forums',
    ]);

// Module C
$config->path('/pub/health')
    ->action('', App\Controller\HealthAction::class, '__invoke', ['GET']);
```

What this means:

- requests under `/pub` inherit `AuthMiddleware`
- the `/pub/forums/` subtree is matched by convention instead of explicit route registration
- `/pub/health` is one exact action

## Entry Points

```php
$config->path(string $prefix = '/');
$config->site(string $site): RouterConfig;
$config->alias(string $site): RouterConfig;
$config->wildcardAlias(string $pattern): RouterConfig;
$config->siteStrategy(string|SiteStrategyInterface $strategy, array $params = []): RouterConfig;
$config->asDefault(): RouterConfig;

$pathBuilder->path(string $prefix = '/'): PathBuilder;
$pathBuilder->strategy(string|StrategyInterface $strategy, array $params = []): PathBuilder;
$pathBuilder->action(string $path, string $class, string $method = '__invoke', array $allowedMethods = []): PathBuilder;
$pathBuilder->middleware(string|MiddlewareInterface $middleware): PathBuilder;
```

This guide refers to the object returned by `path()` as `PathBuilder`. At runtime it is an anonymous builder object.

## Working With Branches

`RouterConfig::path()` starts from the root of the current site tree.

Each additional `path(...)` call on the returned builder descends from the current node, so separate modules can extend the same branch in any order.

Example:

```php
$config->path('/pub')
    ->path('forums')
    ->path('list')
    ->middleware('ListMiddleware');
```

This configures the branch `['pub', 'forums', 'list']`.

Important rules:

- configured prefixes are lowercased immediately
- `path('/pub')`, `path('/pub/')`, `path('pub')`, and `path('/PUB')` point to the same branch when started from router root
- on a builder, `path('forums')` and `path('/forums')` mean the same child branch
- on a builder, a leading slash does not reset traversal to router root
- `path()` and `path('/')` keep the current node
- `path('')` creates one explicit empty segment
- repeated slashes inside configured prefixes are preserved as explicit empty segments
- a trailing slash does not create an extra child node

Examples with explicit empty segments:

- `/pub//forums/` -> `['pub', '', 'forums']`
- `$config->path('/pub')->path('')->path('forums/')` -> `['pub', '', 'forums']`

## Exact Actions

`action(...)` is the helper for one exact terminal route on the current branch.

```php
$config->path('/health')
    ->action('', App\Controller\HealthAction::class, '__invoke', ['GET']);
```

Rules:

- `''` means the current node without trailing slash
- `'/'` means the current node with trailing slash
- `'check'` and `'/check'` mean the same exact child remainder
- internal repeated slashes are preserved
- `allowedMethods` turns method mismatches into `METHOD_NOT_ALLOWED`
- if you need an exact `//` route as one action, use `path('/')->action('/')`

Typical example:

```php
$config->path('/health')->action('', App\Controller\HealthAction::class, '__invoke', ['GET']);
$config->path('/health')->action('/', App\Controller\HealthSlashAction::class, '__invoke', ['GET']);
$config->path('/health')->action('/check/', App\Controller\HealthCheckSlashAction::class, '__invoke', ['GET']);
```

Exact double-slash route example:

```php
$config->path('/')->action('/', App\Controller\DoubleSlashAction::class, '__invoke', ['GET']);
```

This configuration matches runtime path `//` and generates `//`.
The plain root path `/` stays a different route.

## Strategies And Middlewares

`strategy(...)` accepts either:

- a strategy class-string plus constructor params
- a ready strategy object

`middleware(...)` accepts either:

- a container service id string
- a ready `MiddlewareInterface` object

If the same middleware class needs different constructor arguments on different branches, register separate container service ids and use those ids in `middleware(...)`.

Strategies shipped in this package are examples of the extension model, not a closed built-in ruleset. Any custom strategy implementing `StrategyInterface` can participate in matching and generation.

## PathToClassStrategy

`PathToClassStrategy` is the main convention-based strategy for controller branches.

Use it when a subtree follows predictable naming and explicit route-by-route registration would become repetitive.

```php
$config->path('/forums/')
    ->strategy(PathToClassStrategy::class, [
        'namespace' => 'App\\Controller\\Forums',
    ]);
```

Rules:

- matched method is always `__invoke`
- HTTP method suffix is case-tolerant on input and normalized internally
- trailing slash in the remainder path becomes an extra namespace separator
- matching checks only `class_exists(...)`
- matching does not use reflection
- missing method-specific class returns `false`, not `METHOD_NOT_ALLOWED`

Examples for namespace `App\\Controller\\Forums`:

- `GET /forums/` -> `App\\Controller\\Forums\\Get`
- `GET /forums/view` -> `App\\Controller\\Forums\\ViewGet`
- `GET /forums/list/` -> `App\\Controller\\Forums\\List\\Get`

## Consumer Strategies

Consumer strategies are useful when part of the URL comes from params but the rest of the branch still follows a convention.

There are two different matching shapes in this group:

- tail consumers work with the whole current remainder path
- segment consumers work with exactly one next segment and then let the tree continue structurally

Built-in consumers:

- `PathTemplateConsumer`
- `SegmentSlugConsumer`
- `SegmentIdConsumer`
- `SegmentSlugIdConsumer`

Example:

```php
use NIH\Router\Strategy\Consumer\PathTemplateConsumer;
use NIH\Router\Strategy\PathToClassStrategy;

$config->path('/blogs/')
    ->strategy(PathTemplateConsumer::class, [
        'pattern' => '{blogId:int}/',
    ])
    ->path('/threads/')
    ->strategy(PathToClassStrategy::class, [
        'namespace' => 'App\\Controller\\Blogs\\Threads',
    ]);
```

This means:

- matching can read `/blogs/25/threads/view`
- generation can use the logical path `/blogs/threads/view` plus `['blogId' => 25]`

Key behavior:

- `PathTemplateConsumer` is a tail consumer: it matches against the whole current remainder path, for example `{blogId:int}/threads/`
- `SegmentSlugConsumer` consumes exactly one segment and stores it as a string
- `SegmentIdConsumer` consumes exactly one digits-only segment
- `SegmentSlugIdConsumer` supports compact `title + separator + id` segments or id-only segments
- `SegmentSlugIdConsumer` keeps matching strict; during generation, any separator occurrences inside the title are normalized to `_`
- generation consumes required params from the params array and leaves unused params to become query parameters later

Two equivalent-looking configurations can mean different things:

```php
use NIH\Router\Strategy\Consumer\PathTemplateConsumer;
use NIH\Router\Strategy\Consumer\SegmentIdConsumer;
use NIH\Router\Strategy\PathToClassStrategy;

$config->path('/blogs/')
    ->strategy(PathTemplateConsumer::class, [
        'pattern' => '{blogId:int}/threads/',
    ])
    ->strategy(PathToClassStrategy::class, [
        'namespace' => 'App\\Controller\\Blogs\\Threads',
    ]);

$config->path('/blogs/')
    ->strategy(SegmentIdConsumer::class, [
        'param' => 'blogId',
    ])
    ->path('/threads/')
    ->strategy(PathToClassStrategy::class, [
        'namespace' => 'App\\Controller\\Blogs\\Threads',
    ]);
```

The first strategy consumes the remainder `25/threads/` as one tail-shaped pattern.
The second strategy consumes only `25`, then the router descends into the structural child `/threads/`.

## Sites And Aliases

Use sites when the same router needs separate trees or aliases for different hosts or other site reference strings.

Typical setup:

```php
$config->site('https://www.example.com')
    ->alias('https://alias.example.com')
    ->wildcardAlias('https://*.example.com');
```

Practical rules:

- `site('https://example.com')` registers or selects one exact site reference
- site references are lowercased and trailing slashes are trimmed immediately
- `site(...)` accepts exact strings only, not wildcard patterns
- `alias(...)` registers one exact alias for the currently selected site
- `alias(...)` treats wildcard characters literally; use `wildcardAlias(...)` for wildcard matching
- `wildcardAlias(...)` accepts exact strings and wildcard patterns
- wildcard `wildcardAlias('https://*.example.com')` requires an already selected exact site
- `asDefault()` changes which site is used by later root-level `path(...)` calls

Useful mental model:

- `site(...)` chooses the tree you are editing
- `alias(...)` and `wildcardAlias(...)` point more incoming site values at that same tree

## Site Strategies

Site strategies run above the path tree.

```php
namespace NIH\Router\Strategy\Site;

interface SiteStrategyInterface
{
    public function match(string &$site, array &$routeParams): bool;

    public function generate(string &$site, array &$params): bool;
}
```

Built-in site strategy:

- `SubdomainReaderSiteStrategy`

It reads the first subdomain label into route params and does not rewrite generation output.

## Reference Notes

These details are usually only important when you are working with edge cases or implementing extensions.

- internal tree nodes keep only `strategies`, `middlewares`, and `children`
- full prefix strings are not stored inside nodes
- the first named site renames the temporary anonymous default tree
- the first exact `alias(...)` or exact `wildcardAlias(...)` can also name that default tree

## References

- [README.md](../README.md)
- [docs/router.md](router.md)
