<?php

declare(strict_types=1);

namespace NIH\Router;

use NIH\Router\Strategy\Site\SiteStrategyInterface;
use NIH\Router\Strategy\StrategyInterface;
use Psr\Http\Server\MiddlewareInterface;

abstract class RouterData
{
    protected string $defaultSiteKey = '__default__';

    protected bool $hasSiteStrategies = false;

    /**
     * @var array<string, string>
     */
    protected array $aliases = [];

    /**
     * @var list<array{
     *     siteKey: string,
     *     pattern: string,
     *     matcher: array{type: 'exact'|'glob'|'regex', value: string}
     * }>
     */
    protected array $wildcardAliases = [];

    /**
     * @var array<string, array{
     *     siteStrategies: list<array{
     *         class?: class-string<SiteStrategyInterface>,
     *         params?: array,
     *         strategy?: SiteStrategyInterface
     *     }>,
     *     root: array{
     *         strategies?: list<array{
     *             class?: class-string<StrategyInterface>,
     *             params?: array,
     *             strategy?: StrategyInterface
     *         }>,
     *         middlewares?: list<MiddlewareInterface|class-string<MiddlewareInterface>>,
     *         children: array<string, array>
     *     }
     * }>
     */
    protected array $sites = [
        '__default__' => [
            'siteStrategies' => [],
            'root' => [
                'children' => [],
            ],
        ],
    ];

    final protected function bindSiteRegistry(self $data): void
    {
        $this->defaultSiteKey = &$data->defaultSiteKey;
        $this->hasSiteStrategies = &$data->hasSiteStrategies;
        $this->aliases = &$data->aliases;
        $this->wildcardAliases = &$data->wildcardAliases;
        $this->sites = &$data->sites;
    }

    /**
     * @param array{
     *     class?: class-string<StrategyInterface>,
     *     params?: array,
     *     strategy?: StrategyInterface
     * } $entry
     */
    protected function strategyInstance(array &$entry): StrategyInterface
    {
        $strategy = $entry['strategy'] ?? null;

        if ($strategy instanceof StrategyInterface) {
            return $strategy;
        }

        /** @var class-string<StrategyInterface> $strategyClass */
        $strategyClass = $entry['class'];
        $strategy = new $strategyClass(...($entry['params'] ?? []));
        $entry['strategy'] = $strategy;

        return $strategy;
    }

    /**
     * @param array{
     *     class?: class-string<SiteStrategyInterface>,
     *     params?: array,
     *     strategy?: SiteStrategyInterface
     * } $entry
     */
    protected function siteStrategyInstance(array &$entry): SiteStrategyInterface
    {
        $strategy = $entry['strategy'] ?? null;

        if ($strategy instanceof SiteStrategyInterface) {
            return $strategy;
        }

        /** @var class-string<SiteStrategyInterface> $strategyClass */
        $strategyClass = $entry['class'];
        $strategy = new $strategyClass(...($entry['params'] ?? []));
        $entry['strategy'] = $strategy;

        return $strategy;
    }

    protected function resolveSiteKey(string $site): ?string
    {
        if ($site === '') {
            return $this->defaultSiteKey;
        }

        if (empty($this->aliases) && empty($this->wildcardAliases)) {
            return null;
        }

        $siteKey = $this->aliases[$site] ?? null;

        if ($siteKey !== null) {
            return $siteKey;
        }

        foreach ($this->wildcardAliases as $entry) {
            // Wildcard aliases are already normalized and precompiled.
            if (match ($entry['matcher']['type']) {
                'exact' => $entry['matcher']['value'] === $site,
                'glob' => fnmatch($entry['matcher']['value'], $site, FNM_NOESCAPE),
                'regex' => preg_match($entry['matcher']['value'], $site) === 1,
            }) {
                return $entry['siteKey'];
            }
        }

        return null;
    }
}
