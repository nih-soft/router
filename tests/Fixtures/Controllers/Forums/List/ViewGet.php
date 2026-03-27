<?php

declare(strict_types=1);

namespace NIH\Router\Tests\Fixtures\Controllers\Forums\List;

final readonly class ViewGet
{
    public function get(): string
    {
        return 'forums-list-view-get';
    }
}
