<?php

declare(strict_types=1);

namespace NIH\Router\Strategy\Consumer;

use NIH\Router\UrlStringHelper;
use NIH\Router\Strategy\StrategyInterface;
use RuntimeException;

final readonly class SegmentSlugIdConsumer implements StrategyInterface
{
    public function __construct(
        private readonly string $id = 'id',
        private readonly ?string $title = null,
        private readonly string $separator = '.',
    ) {
        if ($this->title === '') {
            throw new RuntimeException('SegmentSlugIdConsumer requires a non-empty "title" parameter.');
        }

        if ($this->id === '') {
            throw new RuntimeException('SegmentSlugIdConsumer requires a non-empty "id" parameter.');
        }

        if ($this->title === $this->id) {
            throw new RuntimeException('SegmentSlugIdConsumer requires different "title" and "id" parameter names.');
        }

        if ($this->separator === '') {
            throw new RuntimeException('SegmentSlugIdConsumer requires a non-empty separator.');
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

        if ($segment === null || $segment === '') {
            return false;
        }

        if ($this->title === null) {
            if (ctype_digit($segment)) {
                $routeParams[$this->id] = (int) $segment;
                $path = $nextPath;
            }

            return false;
        }

        $parts = explode($this->separator, $segment, 3);
        
        $count = count($parts);
        switch ($count) {
            case 2:
                $segment = $parts[1];
                break;
            case 3:
                return false;
        }
        if (!ctype_digit($segment)) {
            return false;
        }

        $routeParams[$this->id] = (int) $segment;
        $routeParams[$this->title] = rawurldecode($count === 1 ? '' : $parts[0]);

        $path = $nextPath;

        return false;
    }

    public function generate(
        string &$prefix,
        string &$path,
        array &$queryParams,
    ): bool {
        $id = $queryParams[$this->id] ?? null;

        if ($id === null) {
            return false;
        }
        if ($this->title === null) {
            $generatedSegment = (int) $id;
        }
        else {
            $title = $queryParams[$this->title] ?? null;
            if ($title === null) {
                $generatedSegment = (int) $id;
            }
            else {
                $generatedTitle = str_replace($this->separator, '_', (string) $title);
                $generatedSegment = rawurlencode($generatedTitle . $this->separator) . (int) $id;
            }

            unset($queryParams[$this->title]);
        }

        unset($queryParams[$this->id]);

        $prefix .= ($prefix === '')
            ? $generatedSegment
            : '/' . $generatedSegment;

        return false;
    }
}
