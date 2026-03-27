<?php

declare(strict_types=1);

namespace NIH\Router\Strategy\Site;

use RuntimeException;

final readonly class SubdomainReaderSiteStrategy implements SiteStrategyInterface
{
    public function __construct(
        private readonly string $param = 'subdomain',
        private readonly bool $required = false,
    ) {
        if ($this->param === '') {
            throw new RuntimeException('SubdomainReaderSiteStrategy requires a non-empty "param" name.');
        }
    }

    public function match(string &$site, array &$routeParams): bool
    {
        $host = parse_url($site, PHP_URL_HOST);

        if (!is_string($host) || $host === '' || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return !$this->required;
        }

        $parts = explode('.', $host, 3);

        if (count($parts) < 3 || $parts[0] === '') {
            return !$this->required;
        }

        $routeParams[$this->param] = $parts[0];

        return true;
    }

    public function generate(string &$site, array &$params): bool
    {
        return true;
    }
}
