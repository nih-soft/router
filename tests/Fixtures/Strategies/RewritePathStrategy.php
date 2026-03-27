<?php

declare(strict_types=1);

namespace NIH\Router\Tests\Fixtures\Strategies;

use NIH\Router\Strategy\StrategyInterface;

final readonly class RewritePathStrategy implements StrategyInterface
{
    public function __construct(
        private readonly string $from,
        private readonly string $to,
    ) {
    }

    public function match(
        string $httpMethod,
        string &$path,
        array &$routeParams,
        array &$queryParams,
        ?string &$class,
        ?string &$method,
        array &$allowedMethods,
    ): bool {
        return false;
    }

    public function generate(
        string &$prefix,
        string &$path,
        array &$queryParams,
    ): bool
    {
        if ($path !== trim($this->from, '/')) {
            return false;
        }

        $path = $this->to === '/'
            ? '/'
            : trim($this->to, '/');

        return true;
    }
}
