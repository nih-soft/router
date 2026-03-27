<?php

declare(strict_types=1);

namespace NIH\Router\Strategy\Consumer;

use NIH\Router\Strategy\StrategyInterface;
use NIH\Router\UrlStringHelper;
use RuntimeException;

final readonly class SegmentSlugConsumer implements StrategyInterface
{
    public function __construct(
        private readonly string $param,
    ) {
        if ($this->param === '') {
            throw new RuntimeException('SegmentSlugConsumer requires a non-empty "param" parameter.');
        }
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
        $nextPath = $path;
        $segment = UrlStringHelper::consumePathSegment($nextPath);

        if ($segment === null) {
            return false;
        }

        $routeParams[$this->param] = rawurldecode($segment);
        $path = $nextPath;

        return false;
    }

    public function generate(
        string &$prefix,
        string &$path,
        array &$queryParams,
    ): bool {
        if (!array_key_exists($this->param, $queryParams)) {
            return false;
        }

        $value = $queryParams[$this->param];
        $segment = rawurlencode((string) $value);

        $prefix = $prefix === ''
            ? $segment
            : $prefix . '/' . $segment;

        unset($queryParams[$this->param]);

        return false;
    }
}
