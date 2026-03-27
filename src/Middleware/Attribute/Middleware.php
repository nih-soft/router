<?php

declare(strict_types=1);

namespace NIH\Router\Middleware\Attribute;

use Attribute;
use Psr\Http\Server\MiddlewareInterface;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Middleware
{
    /**
     * @param class-string<MiddlewareInterface>|MiddlewareInterface $class
     */
    public function __construct(
        public MiddlewareInterface|string $class,
    ) {
    }
}
