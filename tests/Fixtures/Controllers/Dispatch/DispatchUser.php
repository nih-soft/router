<?php

declare(strict_types=1);

namespace NIH\Router\Tests\Fixtures\Controllers\Dispatch;

final readonly class DispatchUser
{
    public function __construct(
        public string $id,
    ) {
    }
}
