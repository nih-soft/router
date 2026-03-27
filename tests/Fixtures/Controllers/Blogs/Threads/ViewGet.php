<?php

declare(strict_types=1);

namespace NIH\Router\Tests\Fixtures\Controllers\Blogs\Threads;

final readonly class ViewGet
{
    public function get(): string
    {
        return 'blogs-threads-view-get';
    }
}
