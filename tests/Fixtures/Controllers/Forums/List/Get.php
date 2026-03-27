<?php

declare(strict_types=1);

namespace NIH\Router\Tests\Fixtures\Controllers\Forums\List;

final readonly class Get
{
    public function __invoke(): string
    {
        return 'forums-list-slash-get';
    }
}
