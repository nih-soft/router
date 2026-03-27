<?php

declare(strict_types=1);

namespace NIH\Router\Tests\Fixtures\Controllers\Users;

final readonly class Index
{
    public function get(): string
    {
        return 'users-index-get';
    }
}
