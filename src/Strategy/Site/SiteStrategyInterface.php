<?php

declare(strict_types=1);

namespace NIH\Router\Strategy\Site;

interface SiteStrategyInterface
{
    /**
     * @param array<string, mixed> $routeParams
     */
    public function match(string &$site, array &$routeParams): bool;

    /**
     * @param array<string, mixed> $params
     */
    public function generate(string &$site, array &$params): bool;
}
