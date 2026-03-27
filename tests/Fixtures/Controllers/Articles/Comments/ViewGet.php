<?php

declare(strict_types=1);

namespace NIH\Router\Tests\Fixtures\Controllers\Articles\Comments;

final readonly class ViewGet
{
    public function get(): string
    {
        return 'ok';
    }
}
