<?php

declare(strict_types=1);

namespace NIH\Router\Tests\Fixtures\Strategies;

use NIH\Router\Strategy\StrategyInterface;
use RuntimeException;

final readonly class ThrowingStrategy implements StrategyInterface
{
    public function match(
        string $httpMethod,
        string &$path,
        array &$routeParams,
        array &$queryParams,
        ?string &$class,
        ?string &$method,
        array &$allowedMethods,
    ): bool {
        throw new RuntimeException('Matcher failure.');
    }

    public function generate(
        string &$prefix,
        string &$path,
        array &$queryParams,
    ): bool
    {
        return false;
    }
}
