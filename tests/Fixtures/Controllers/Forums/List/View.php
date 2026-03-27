<?php

declare(strict_types=1);

namespace NIH\Router\Tests\Fixtures\Controllers\Forums\List;

final readonly class View
{
    public function get(): string
    {
        return 'forums-list-view-get';
    }

    public function post(): string
    {
        return 'forums-list-view-post';
    }
}
