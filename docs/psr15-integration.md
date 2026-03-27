# PSR-15 Integration

The PSR-15 layer is split into two middlewares:

- `RouteMatchMiddleware`
- `RouteDispatchMiddleware`

This keeps matching separate from execution.

## Mental Model

The integration is a two-step pipeline:

1. `RouteMatchMiddleware` computes `RouteMatchResult` and writes it into a request attribute.
2. `RouteDispatchMiddleware` reads that attribute and decides whether to dispatch, pass through, or return `405`.

The only strict requirement is order: `RouteMatchMiddleware` must run before `RouteDispatchMiddleware`.

Everything else in your application pipeline may sit:

- before matching
- between matching and dispatch
- after dispatch as a fallback for `NOT_FOUND`

## Minimal Pipeline

Typical composition:

```php
use NIH\Router\Middleware\RouteDispatchMiddleware;
use NIH\Router\Middleware\RouteMatchMiddleware;
use NIH\Router\RouteMatcher;

$middlewareDispatcher->pipe(new ErrorHandlingMiddleware());
$middlewareDispatcher->pipe(new BodyParsingMiddleware());
$middlewareDispatcher->pipe(new RouteMatchMiddleware(new RouteMatcher($config)));
$middlewareDispatcher->pipe(new CurrentUserMiddleware());
$middlewareDispatcher->pipe(new RouteDispatchMiddleware($container, $responseFactory));
$middlewareDispatcher->pipe(new NotFoundMiddleware());
```

Or, in frameworks with `add()`-style registration:

```php
$app->add(new ErrorHandlingMiddleware());
$app->add(new BodyParsingMiddleware());
$app->add(new RouteMatchMiddleware(new RouteMatcher($config)));
$app->add(new CurrentUserMiddleware());
$app->add(new RouteDispatchMiddleware($container, $responseFactory));
```

If your framework executes `add()` registrations in reverse order, apply the same logical ordering even if the registration calls must be reversed.

Useful reading of this pipeline:

- middleware before `RouteMatchMiddleware` runs for every request before routing
- middleware between the two router middlewares can add request attributes later consumed by dispatch
- fallback middleware after `RouteDispatchMiddleware` handles `NOT_FOUND`

## RouteMatchMiddleware

Constructor:

```php
new RouteMatchMiddleware(RouteMatcher $matcher, string $attributeName = RouteMatchResult::class)
```

What it reads from the request:

- path from `getUri()->getPath()`
- HTTP method from `getMethod()`
- site from `scheme://authority` when both are available
- query params from `getQueryParams()`

If the request URI is relative and has no `scheme://authority`, matching still uses the default site tree, but site strategies are not executed.

What it writes:

- one `RouteMatchResult` into the configured request attribute

Behavior:

- always forwards the request to the next handler
- preserves `FOUND`, `NOT_FOUND`, and `METHOD_NOT_ALLOWED` from the matcher
- propagates matcher exceptions instead of swallowing them

Example:

```php
$matchMiddleware = new RouteMatchMiddleware(
    new RouteMatcher($config),
    RouteMatchResult::class,
);
```

## RouteDispatchMiddleware

Constructor:

```php
new RouteDispatchMiddleware(
    ContainerInterface $container,
    ResponseFactoryInterface $responseFactory,
    string $attributeName = RouteMatchResult::class,
)
```

It expects that the request already contains a `RouteMatchResult` under the configured attribute name.

Behavior by status:

- `FOUND`: resolve the controller from the container and dispatch it
- `NOT_FOUND`: pass the request to the next handler unchanged
- `METHOD_NOT_ALLOWED`: return `405`

For `METHOD_NOT_ALLOWED`:

- the response is created through `ResponseFactoryInterface`
- the `Allow` header is added when `allowedMethods` is not empty

For `FOUND`:

- the controller class is resolved from the container
- non-object container results throw
- non-callable targets are downgraded to `NOT_FOUND` before forwarding
- route params are written into request attributes
- match query params replace the request query bag when present
- the internal route-match attribute is removed before inner dispatch

## Dispatch Order

When a `FOUND` route is callable, execution order is:

1. branch middlewares collected by the router
2. controller class `#[Middleware]`
3. controller class `#[Before]`
4. action method `#[Before]`
5. controller action
6. action method `#[After]`
7. controller class `#[After]`

Important details:

- route/path middlewares run before controller class `#[Middleware]`
- branch middlewares may come from root, parent branches, and the terminal branch
- middleware instances stored directly in router config are supported
- `#[Before]` and `#[After]` run inside the terminal handler, not as PSR-15 middleware

Short-circuit behavior:

- a controller-level `before` callback that returns `ResponseInterface` skips the whole inner block
- an action-level `before` callback that returns `ResponseInterface` skips the action and action-level `after`
- controller-level `after` still runs when dispatch already has a response
- exceptions bubble unless caught by outer middleware

Current route-specific PSR-15 pipeline execution uses `nih/middleware-dispatcher` internally. Treat it as a dispatch-layer implementation detail, not as stable router API.

## Dispatch Attributes

Supported attributes:

```php
#[Middleware(MiddlewareInterface|string $class)]
#[Before(object|string $class, string $method = '__invoke')]
#[After(object|string $class, string $method = '__invoke')]
#[FromAttribute(?string $key = null)]
#[FromQuery(?string $key = null)]
```

### `#[Middleware]`

Rules:

- allowed on controller classes only
- repeatable
- accepts a middleware service id string or a ready `MiddlewareInterface` object

### `#[Before]` and `#[After]`

Rules:

- allowed on controller classes and action methods
- repeatable
- accept a class-string or a ready object instance
- default method is `__invoke`
- `#[Before(self::class, 'loadForum')]` reuses the controller instance when possible

These callbacks may:

- return `ServerRequestInterface` to replace the current request
- return `ResponseInterface` to short-circuit or replace the current response

### `#[FromAttribute]` and `#[FromQuery]`

Rules:

- apply to one callable parameter
- select an explicit source for that parameter
- disable cross-source fallback for that parameter
- use the parameter name when no explicit key is provided

## Argument Resolution

`RouteDispatchMiddleware` uses the internal static `ArgumentResolver` before delegating the final call to `NIH\Container\Instantiator`.

Resolution order without explicit source attributes:

1. reserved runtime parameters
2. request attributes by parameter name
3. query params for scalar and array fallbacks

Reserved runtime parameters:

- `ServerRequestInterface` parameters receive the current request
- `ResponseInterface` parameters receive the current response in `after` callbacks when available

Baseline automatic resolution:

- object parameters are resolved from request attributes when the runtime value matches the declared type
- scalar parameters first try request attributes by parameter name
- if attribute lookup misses, scalar values may fall back to query params
- array parameters may resolve from attributes first, then from query params
- if an attribute key exists but is incompatible, query fallback does not win over it

Explicit-source behavior:

- `FromQuery` supports only `int`, `float`, and `string`
- `FromAttribute` supports object values when the runtime value matches the declared type
- required explicit-source misses throw unless the parameter has a default value or allows `null`
- one parameter must not declare both `FromAttribute` and `FromQuery`

## Common Integration Failures

These are the mistakes most likely to surprise an application integrator.

- `RouteDispatchMiddleware` throws if the configured match attribute is missing
- `RouteDispatchMiddleware` throws if that attribute exists but is not a `RouteMatchResult`
- a container entry for a `FOUND` target must resolve to an object
- a route action must return `ResponseInterface`
- `__call()` is not enough for dispatch; the resolved method must physically exist and be callable
- a non-callable `FOUND` target is downgraded to `NOT_FOUND`, and the next handler receives a fresh `RouteMatchResult::notFound(...)`

## References

- [README.md](../README.md)
- [docs/configuration.md](configuration.md)
- [docs/router.md](router.md)
