<?php

declare(strict_types=1);

namespace NIH\Router\Tests\Fixtures\Strategies;

use NIH\Router\Strategy\StrategyInterface;

final readonly class QueryRewriteMatchStrategy implements StrategyInterface
{
    public function __construct(
        private string $path,
        private string $class = 'Query\\Target',
        private string $method = '__invoke',
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
        if ($path !== $this->path) {
            return false;
        }

        if (isset($queryParams['page']) && is_scalar($queryParams['page'])) {
            $queryParams['page'] = (string) ((int) $queryParams['page'] + 1);
        }

        unset($queryParams['token']);
        $queryParams['resolved'] = 'yes';

        $class = $this->class;
        $method = $this->method;

        return true;
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
