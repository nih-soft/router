<?php

declare(strict_types=1);

namespace NIH\Router;

use NIH\Router\Strategy\StrategyInterface;
use NIH\Router\Strategy\ActionStrategy;
use NIH\Router\Strategy\Site\SiteStrategyInterface;
use Psr\Http\Server\MiddlewareInterface;
use RuntimeException;

final class RouterConfig extends RouterData
{
    private ?string $currentSiteKey = null;

    public function __construct()
    {
    }

    public function path(string $prefix = '/')
    {
        $siteKey = $this->currentSiteKey ?? $this->defaultSiteKey;

        return new class($this->sites[$siteKey]['root'], $prefix) {
            /**
             * Current tree node mutated directly by the builder.
             *
             * @var array{
             *     strategies?: list<array{
             *         class?: class-string<StrategyInterface>,
             *         params?: array,
             *         strategy?: StrategyInterface
             *     }>,
             *     middlewares?: list<MiddlewareInterface|class-string<MiddlewareInterface>>,
             *     children?: array<string, array>
             * }
             */
            private array $node;

            public function __construct(array &$node, string $prefix)
            {
                $this->node = &$node;

                if ($prefix !== '/') {
                    $this->node = &$this->descend($node, strtolower($prefix));
                }
            }

            public function path(string $prefix = '/'): self
            {
                return new self($this->node, $prefix);
            }

            /**
             * @param class-string<StrategyInterface>|StrategyInterface $strategy
             */
            public function strategy(string|StrategyInterface $strategy, array $params = []): self
            {
                $this->node['strategies'][] = $strategy instanceof StrategyInterface
                    ? ['strategy' => $strategy]
                    : ['class' => $strategy, 'params' => $params];

                return $this;
            }

            /**
             * @param class-string<MiddlewareInterface>|MiddlewareInterface $middleware
             */
            public function middleware(string|MiddlewareInterface $middleware): self
            {
                $this->node['middlewares'][] = $middleware;

                return $this;
            }

            /**
             * @param list<string> $allowedMethods
             */
            public function action(string $path, string $class, string $method = '__invoke', array $allowedMethods = []): self
            {
                return $this->strategy(new ActionStrategy($path, $class, $method, $allowedMethods));
            }

            /**
             * @param array{
             *     strategies?: list<array{
             *         class?: class-string<StrategyInterface>,
             *         params?: array,
             *         strategy?: StrategyInterface
             *     }>,
             *     middlewares?: list<MiddlewareInterface|class-string<MiddlewareInterface>>,
             *     children?: array<string, array>
             * } $node
             * @return array{
             *     strategies?: list<array{
             *         class?: class-string<StrategyInterface>,
             *         params?: array,
             *         strategy?: StrategyInterface
             *     }>,
             *     middlewares?: list<MiddlewareInterface|class-string<MiddlewareInterface>>,
             *     children?: array<string, array>
             * }
             */
            private function &descend(array &$node, string $prefix): array
            {
                if ($prefix !== '' && $prefix[0] === '/') {
                    $prefix = substr($prefix, 1);
                }

                if ($prefix === '') {
                    $node['children'] ??= [];
                    $node['children'][''] ??= [];
                    $node = &$node['children'][''];

                    return $node;
                }

                while (($segment = UrlStringHelper::consumePathSegment($prefix)) !== null) {
                    $node['children'] ??= [];
                    $node['children'][$segment] ??= [];
                    $node = &$node['children'][$segment];
                }

                return $node;
            }
        };
    }

    public function site(string $site): self
    {
        $site = $this->normalizeSiteReference($site);

        // Primary site keys must stay exact. Wildcards belong to wildcard aliases only.
        if (strpbrk($site, '*?[') !== false) {
            throw new RuntimeException('site() accepts only exact site URLs.');
        }

        if ($this->defaultSiteKey === '__default__') {
            $this->sites[$site] = $this->sites['__default__'];
            unset($this->sites['__default__']);
            $this->defaultSiteKey = $site;
        } else {
            $this->sites[$site] ??= [
                'siteStrategies' => [],
                'root' => [
                    'children' => [],
                ],
            ];
        }

        $this->aliases[$site] = $site;

        if ($this->currentSiteKey === $site) {
            return $this;
        }

        $clone = clone $this;
        $clone->bindSiteRegistry($this);
        $clone->currentSiteKey = $site;

        return $clone;
    }

    public function alias(string $site): self
    {
        if ($this->currentSiteKey === null && $this->defaultSiteKey === '__default__') {
            return $this->site($site);
        }

        $siteKey = $this->currentSiteKey ?? $this->defaultSiteKey;
        $site = $this->normalizeSiteReference($site);
        $this->aliases[$site] = $siteKey;

        return $this;
    }

    public function wildcardAlias(string $pattern): self
    {
        $pattern = $this->normalizeSiteReference($pattern);
        if (strpbrk($pattern, '*?[') === false) {
            $matcher = [
                'type' => 'exact',
                'value' => $pattern,
            ];
        } elseif (function_exists('fnmatch')) {
            $matcher = [
                'type' => 'glob',
                'value' => $pattern,
            ];
        } else {
            $matcher = [
                'type' => 'regex',
                'value' => '~^'
                    . str_replace(
                        ['\\*', '\\?'],
                        ['.*', '.'],
                        preg_quote($pattern, '~'),
                    )
                    . '$~',
            ];
        }

        if ($this->currentSiteKey === null && $this->defaultSiteKey === '__default__') {
            if ($matcher['type'] !== 'exact') {
                throw new RuntimeException('wildcardAlias() with a wildcard pattern requires a selected site.');
            }

            return $this->site($pattern);
        }

        $siteKey = $this->currentSiteKey ?? $this->defaultSiteKey;

        if ($matcher['type'] === 'exact') {
            $this->aliases[$pattern] = $siteKey;

            return $this;
        }

        $this->registerWildcardAliasPattern($pattern, $matcher, $siteKey);

        return $this;
    }

    /**
     * @param class-string<SiteStrategyInterface>|SiteStrategyInterface $strategy
     */
    public function siteStrategy(string|SiteStrategyInterface $strategy, array $params = []): self
    {
        $siteKey = $this->currentSiteKey ?? $this->defaultSiteKey;
        $this->hasSiteStrategies = true;

        if ($strategy instanceof SiteStrategyInterface) {
            $this->sites[$siteKey]['siteStrategies'][] = [
                'strategy' => $strategy,
            ];
        } else {
            $this->sites[$siteKey]['siteStrategies'][] = [
                'class' => $strategy,
                'params' => $params,
            ];
        }

        return $this;
    }

    public function asDefault(): self
    {
        $this->defaultSiteKey = $this->currentSiteKey ?? $this->defaultSiteKey;

        return $this;
    }

    private function normalizeSiteReference(string $site): string
    {
        $site = strtolower(rtrim($site, '/'));

        if ($site === '' || $site === '__default__') {
            throw new RuntimeException('Site reference must not be empty or reserved.');
        }

        if (strcspn($site, "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x20\x7f") !== strlen($site)) {
            throw new RuntimeException('Site reference contains unsupported whitespace or control characters.');
        }

        return $site;
    }

    /**
     * @param array{type: 'glob'|'regex', value: string} $matcher
     */
    private function registerWildcardAliasPattern(string $pattern, array $matcher, string $siteKey): void
    {
        foreach ($this->wildcardAliases as $entry) {
            if ($entry['pattern'] !== $pattern) {
                continue;
            }

            if ($entry['siteKey'] !== $siteKey) {
                throw new RuntimeException(sprintf('Wildcard site alias "%s" is already assigned to another site.', $pattern));
            }

            return;
        }

        $this->wildcardAliases[] = [
            'siteKey' => $siteKey,
            'pattern' => $pattern,
            'matcher' => $matcher,
        ];
    }

}
