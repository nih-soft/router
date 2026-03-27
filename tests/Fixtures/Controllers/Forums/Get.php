<?php

declare(strict_types=1);

namespace NIH\Router\Tests\Fixtures\Controllers\Forums;

final readonly class Get
{
    public function get(): string
    {
        return 'forums-get';
    }
}
