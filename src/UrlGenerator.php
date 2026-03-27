<?php

declare(strict_types=1);

namespace NIH\Router;

use RuntimeException;

final class UrlGenerator extends RouterData
{
    public function __construct(
        RouterConfig $config,
    ) {
        $this->bindSiteRegistry($config);
    }

    public function generatePath(string $path, array $queryParams = [], bool $throwOnError = false): string
    {
        $generatedPath = $this->generate($path, $queryParams, $this->defaultSiteKey, $throwOnError);

        return $generatedPath === null
            ? ''
            : '/' . $generatedPath;
    }

    public function generateUrl(
        string $path,
        array $queryParams = [],
        string $site = '',
        ?string $fragment = null,
        bool $throwOnError = false,
    ): string {
        $siteKey = $this->defaultSiteKey;
        $generatedSite = '';

        if ($site !== '') {
            // Normalize the requested target site before registry lookups.
            $site = strtolower(rtrim($site, '/'));
            $siteKey = $this->resolveSiteKey($site);

            if ($siteKey === null) {
                if ($throwOnError) {
                    throw new RuntimeException(sprintf('Unknown site "%s".', $site));
                }

                return '';
            }

            $generatedSite = $site;
        } elseif ($siteKey !== '__default__') {
            $generatedSite = $siteKey;
        }

        if ($generatedSite === '') {
            if ($throwOnError) {
                throw new RuntimeException('Site is required for absolute URL generation.');
            }

            return '';
        }

        if ($this->hasSiteStrategies) {
            foreach ($this->sites[$siteKey]['siteStrategies'] as &$siteStrategy) {
                if (!$this->siteStrategyInstance($siteStrategy)->generate($generatedSite, $queryParams)) {
                    if ($throwOnError) {
                        throw new RuntimeException(sprintf('Unable to generate site for "%s".', $path));
                    }

                    return '';
                }
            }
            unset($siteStrategy);
        }

        $generatedPath = $this->generate($path, $queryParams, $siteKey, $throwOnError);

        if ($generatedPath === null) {
            return '';
        }

        $url = $generatedSite . '/' . $generatedPath;

        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        if ($fragment !== null && $fragment !== '') {
            $url .= '#' . rawurlencode($fragment);
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    private function generate(
        string $path,
        array &$queryParams,
        string $siteKey,
        bool $throwOnError,
    ): ?string {
        // Normalize the logical path once before walking the config tree.
        $path = strtolower($path);

        if ($path !== '' && $path[0] === '/') {
            $path = substr($path, 1);
        }

        $node = &$this->sites[$siteKey]['root'];
        $prefix = null;

        // Let strategies emit or rewrite path prefixes at the current node before
        // falling back to structural child traversal.
        while (true) {
            $generatedPrefix = '';

            foreach ($node['strategies'] ?? [] as &$strategy) {
                if ($this->strategyInstance($strategy)->generate($generatedPrefix, $path, $queryParams)) {
                    return $this->finalize($prefix, $generatedPrefix, $path);
                }
            }
            unset($strategy);

            // Intermediate strategy prefixes are segment-like. Ignore a trailing
            // slash here so structural traversal remains the single owner of
            // explicit separators between tree nodes.
            $generatedPrefix = rtrim($generatedPrefix, '/');

            // Commit the generated prefix before consuming the next segment
            // from the remaining logical path.
            $prefix = $this->appendFragment($prefix, $generatedPrefix);

            if ($path === '') {
                break;
            }

            $segment = UrlStringHelper::consumePathSegment($path);

            if ($segment === null || !isset($node['children'][$segment]) || !is_array($node['children'][$segment])) {
                break;
            }

            $prefix = $this->appendSegment($prefix, $segment);
            $node = &$node['children'][$segment];
        }

        // Generation either throws on failure or falls back to the empty
        // string contract used by the public facade.
        if ($throwOnError) {
            throw new RuntimeException(sprintf('Unable to generate path for "%s".', $path));
        }

        return null;
    }

    private function appendFragment(?string $prefix, string $prefixPart): ?string
    {
        if ($prefixPart === '') {
            return $prefix;
        }

        return $prefix === null
            ? $prefixPart
            : $prefix . '/' . $prefixPart;
    }

    private function appendSegment(?string $prefix, string $segment): string
    {
        return $prefix === null
            ? $segment
            : $prefix . '/' . $segment;
    }

    private function finalize(?string $prefix, string $generatedPrefix, string $path): string
    {
        $generated = $this->appendFragment($prefix, $generatedPrefix);

        if ($path === '/') {
            // A terminal "/" at the root means an explicit empty segment
            // under root, which generates as "//" after the public leading slash.
            if ($generated === null) {
                return '/';
            }

            if ($generated === '') {
                return '';
            }

            return str_ends_with($generated, '/')
                ? $generated
                : $generated . '/';
        }

        return $this->appendFragment($generated, $path) ?? '';
    }
}
