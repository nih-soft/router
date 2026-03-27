<?php
namespace NIH\Router {
    use NIH\Router\Strategy\StrategyInterface;

    final class PathBuilder
    {
        public function path(string $prefix = '/'): self
        {
            return $this;
        }

        /**
         * @param class-string<StrategyInterface>|StrategyInterface $strategy
         */
        public function strategy(string|StrategyInterface $strategy, array $params = []): self
        {
            return $this;
        }

        /**
         * @param class-string<MiddlewareInterface>|MiddlewareInterface $middleware
         */
        public function middleware(string|MiddlewareInterface $middleware): self
        {
            return $this;
        }

        /**
         * @param list<string> $allowedMethods
         */
        public function action(string $path, string $class, string $method = '__invoke', array $allowedMethods = []): self
        {
            return $this;
        }
    }
}

namespace PHPSTORM_META {

    override(
        \NIH\Router\RouterConfig::path(0),
        map([
            '' => \NIH\Router\PathBuilder::class,
        ])
    );
}
