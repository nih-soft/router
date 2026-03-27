<?php

declare(strict_types=1);

namespace NIH\Router\Tests\Fixtures\Controllers\Users\Profile;

final readonly class ViewGet
{
    public function get(): string
    {
        return 'users-profile-view-get';
    }
}
