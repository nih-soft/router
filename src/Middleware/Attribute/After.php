<?php

declare(strict_types=1);

namespace NIH\Router\Middleware\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class After
{
    /**
     * @param class-string<object>|object $class
     */
    public function __construct(
        public object|string $class,
        public string $method = '__invoke',
    ) {
    }
}
