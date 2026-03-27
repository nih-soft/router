# Router

The core runtime is made of two services:

- `RouteMatcher` for incoming request matching
- `UrlGenerator` for path and URL generation

Both work with strings and arrays. They do not depend on PSR-7 or PSR-11.

## Mental Model

There are two different views of a route at runtime:

- matching works with the real incoming path, such as `/blogs/25/threads/view`
- generation works with the logical configured path, such as `/blogs/threads/view`, plus params used by consumer strategies

This distinction is important when configuration mixes a consumer strategy with a convention-based subtree.

Example:

```php
$result = $matcher->match('/blogs/25/threads/view', 'GET');

$path = $generator->generatePath('/blogs/threads/view', [
    'blogId' => 25,
]);
```

Both calls refer to the same configured branch, but they use different inputs:

- matching reads the full runtime URL shape
- generation starts from the logical branch path and lets strategies insert URL fragments from params

Consumer strategies do not all consume the same amount of path:

- tail consumers such as `PathTemplateConsumer` match against the whole current remainder path
- segment consumers such as `SegmentIdConsumer`, `SegmentSlugConsumer`, and `SegmentSlugIdConsumer` consume one segment and then return control to tree traversal

## RouteMatcher

```php
public function match(
    string $path,
    string $httpMethod,
    string $site = '',
    array $queryParams = [],
): RouteMatchResult
```

Use `match()` when you already have the runtime path, HTTP method, optional site string, and optional query params.

What it does:

- resolves the site tree
- runs site strategies only when an explicit site string was provided
- normalizes the runtime path once
- walks the configured tree
- lets strategies match the current branch
- collects branch middlewares along the matched path

Runtime normalization rules:

- runtime paths are lowercased once
- runtime sites are lowercased once when provided
- trailing slash is trimmed from sites
- only the first leading slash is trimmed from paths
- repeated slashes are preserved
- backslashes are not rewritten

Examples:

- `/PUB/Forums/View` matches the same way as `/pub/forums/view`
- `//pub/forums/` keeps its second leading slash as part of the runtime path
- `/pub//forums/` keeps the internal empty segment

## Match Result

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

Status meaning:

- `FOUND` contains the resolved target, route params, query params, and collected branch middlewares
- `NOT_FOUND` means no route matched
- `METHOD_NOT_ALLOWED` means the logical route matched, but the strategy advertised different allowed methods

Important behavior:

- a missing `PathToClassStrategy` method-specific class is still `NOT_FOUND`, not `METHOD_NOT_ALLOWED`
- `METHOD_NOT_ALLOWED` is produced only when a strategy explicitly reports allowed methods for that logical route

## UrlGenerator

```php
public function generatePath(string $path, array $queryParams = [], bool $throwOnError = false): string;

public function generateUrl(
    string $path,
    array $queryParams = [],
    string $site = '',
    ?string $fragment = null,
    bool $throwOnError = false,
): string;
```

Generation uses the same configured tree as matching, but starts from a logical path.

Example:

```php
$path = $generator->generatePath('/blogs/threads/view', [
    'blogId' => 25,
]);

$url = $generator->generateUrl('/blogs/threads/view', [
    'blogId' => 25,
    'page' => 2,
]);
```

Expected result:

- `generatePath(...)` -> `/blogs/25/threads/view`
- `generateUrl(...)` -> `https://example.com/blogs/25/threads/view?page=2`

What it does:

- resolves the target site for absolute URLs
- runs site strategies before path generation
- walks the same route tree as matching
- lets strategies emit or rewrite generated fragments
- leaves unused params to become query parameters later

## Path Generation Rules

`generatePath(...)`:

- returns a path starting with `/`
- uses the current default site tree internally
- returns an empty string on failure when `throwOnError = false`
- throws `RuntimeException` on failure when `throwOnError = true`

Generated paths preserve the same meaningful slash distinctions as the configured tree:

- `/health` and `/health/` can be different generated paths
- an exact `//` route is configured as `path('/')->action('/')`
- repeated slashes are preserved when the configured branch contains explicit empty segments
- only the first leading slash is trimmed from the logical input before generation

## Absolute URL Generation Rules

`generateUrl(...)`:

- returns an absolute URL
- requires a resolvable site, either from the default named site or from the explicit `site` argument
- returns an empty string on failure when `throwOnError = false`
- throws `RuntimeException` on failure when `throwOnError = true`
- appends unused params as a query string
- appends `fragment` as a URL fragment when provided

Typical failure cases:

- the logical path cannot be generated from the configured tree
- the router has no named default site and no explicit `site` argument was provided
- the explicit `site` argument does not resolve to any configured site or alias
- a site strategy rejects generation

## Sites At Runtime

Both `RouteMatcher` and `UrlGenerator` work with the site layer before path handling.

This allows:

- separate site trees
- exact aliases
- wildcard aliases
- site strategies that read params from the incoming site or write them back during generation

For matching, site strategies run only when a runtime site string is actually available.
If `match()` is called without `site`, matching stays on the default site tree and does not try to infer site-derived params.

Example:

- matching `/pub/forums/view` on `https://acme.example.com` may extract `tenant = acme`
- generation of `/pub/forums/view` with `['tenant' => 'acme']` may produce `https://acme.example.com/pub/forums/view`

## Runtime Notes

These details are usually only relevant when reasoning about hot paths or extension behavior.

- matcher and generator bind configured strategies lazily on first use
- root misses are optimized for fast first-segment rejection when possible
- hot-path APIs stay string/array based; PSR-7 lives in middleware only

## References

- [README.md](../README.md)
- [docs/configuration.md](configuration.md)
- [docs/psr15-integration.md](psr15-integration.md)
