<?php

declare(strict_types=1);

namespace NIH\Router\Tests\Fixtures\SiteStrategies;

use NIH\Router\Strategy\Site\SiteStrategyInterface;

final readonly class TenantSubdomainSiteStrategy implements SiteStrategyInterface
{
    public function __construct(
        private readonly string $param = 'tenant',
        private readonly string $canonical = 'https://www.example.com',
        private readonly string $suffix = '.example.com',
    ) {
    }

    public function match(string &$site, array &$routeParams): bool
    {
        if (!str_starts_with($site, 'https://') || !str_ends_with($site, $this->suffix)) {
            return $site === $this->canonical;
        }

        $tenant = substr($site, 8, -strlen($this->suffix));

        if ($tenant === '' || $tenant === 'www') {
            return false;
        }

        $routeParams[$this->param] = $tenant;
        $site = $this->canonical;

        return true;
    }

    public function generate(string &$site, array &$params): bool
    {
        $tenant = $params[$this->param] ?? null;

        if ($tenant === null) {
            return true;
        }

        $tenant = is_int($tenant) ? (string) $tenant : $tenant;

        if (!is_string($tenant) || $tenant === '' || str_contains($tenant, '.')) {
            return false;
        }

        unset($params[$this->param]);
        $site = 'https://' . strtolower($tenant) . $this->suffix;

        return true;
    }
}
