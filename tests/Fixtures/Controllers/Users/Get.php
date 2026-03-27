<?php

declare(strict_types=1);

namespace NIH\Router\Tests\Fixtures\Controllers\Users;

final readonly class Get
{
    public function get(): string
    {
        return 'users-get';
    }
}
