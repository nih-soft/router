<?php

declare(strict_types=1);

namespace NIH\Router\Tests\Fixtures\Controllers\Dispatch;

final readonly class DispatchService
{
    public function __construct(
        public string $name,
    ) {
    }
}
