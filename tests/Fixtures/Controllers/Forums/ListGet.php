<?php

declare(strict_types=1);

namespace NIH\Router\Tests\Fixtures\Controllers\Forums;

final readonly class ListGet
{
    public function __invoke(): string
    {
        return 'forums-list-get';
    }
}
