<?php

declare(strict_types=1);

namespace NIH\Router\Middleware\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class FromAttribute
{
    public function __construct(
        public ?string $key = null,
    ) {
    }
}
