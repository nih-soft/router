<?php

declare(strict_types=1);

namespace NIH\Router;

final readonly class UrlStringHelper
{
    public static function consumePathSegment(string &$path): ?string
    {
        if ($path === '' || $path === '/') {
            return null;
        }

        $position = strpos($path, '/');

        if ($position === false) {
            $segment = $path;
            $path = '';

            return $segment;
        }

        $segment = substr($path, 0, $position);
        if ($position === strlen($path) - 1) {
            $path = '/';

            return $segment;
        }

        $path = substr($path, $position + 1);

        return $segment;
    }

}
