<?php

declare(strict_types=1);

namespace NIH\Router\Tests;

use NIH\Router\Strategy\PathToClassStrategy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PathToClassStrategyTest extends TestCase
{
    public function test_it_matches_index_controller_as_invokable_action(): void
    {
        $strategy = new PathToClassStrategy('NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums');
        [$matched, $path, $routeParams, $class, $method, $allowedMethods] = $this->match($strategy, 'GET', '');

        $this->assertTrue($matched);
        $this->assertSame('', $path);
        $this->assertSame([], $routeParams);
        $this->assertSame('NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums\\Get', $class);
        $this->assertSame('__invoke', $method);
        $this->assertSame([], $allowedMethods);
    }

    public function test_it_appends_http_method_suffix_to_terminal_class_name(): void
    {
        $strategy = new PathToClassStrategy('NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums');
        [$matched, $path, $routeParams, $class, $method] = $this->match($strategy, 'GET', 'list/view');

        $this->assertTrue($matched);
        $this->assertSame('list/view', $path);
        $this->assertSame([], $routeParams);
        $this->assertSame('NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums\\List\\ViewGet', $class);
        $this->assertSame('__invoke', $method);
    }

    public function test_it_normalizes_http_method_case_when_building_suffix(): void
    {
        $strategy = new PathToClassStrategy('NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums');
        [$matched, $path, $routeParams, $class, $method] = $this->match($strategy, 'gEt', 'list/view');

        $this->assertTrue($matched);
        $this->assertSame('list/view', $path);
        $this->assertSame([], $routeParams);
        $this->assertSame('NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums\\List\\ViewGet', $class);
        $this->assertSame('__invoke', $method);
    }

    public function test_it_distinguishes_a_trailing_slash_from_the_same_path_without_it(): void
    {
        $strategy = new PathToClassStrategy('NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums');

        [$matchedWithoutSlash, $pathWithoutSlash, $routeParamsWithoutSlash, $classWithoutSlash, $methodWithoutSlash] = $this->match($strategy, 'GET', 'list');
        [$matchedWithSlash, $pathWithSlash, $routeParamsWithSlash, $classWithSlash, $methodWithSlash] = $this->match($strategy, 'GET', 'list/');

        $this->assertTrue($matchedWithoutSlash);
        $this->assertSame('list', $pathWithoutSlash);
        $this->assertSame([], $routeParamsWithoutSlash);
        $this->assertSame('NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums\\ListGet', $classWithoutSlash);
        $this->assertSame('__invoke', $methodWithoutSlash);

        $this->assertTrue($matchedWithSlash);
        $this->assertSame('list/', $pathWithSlash);
        $this->assertSame([], $routeParamsWithSlash);
        $this->assertSame('NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums\\List\\Get', $classWithSlash);
        $this->assertSame('__invoke', $methodWithSlash);
    }

    public function test_it_returns_no_match_when_method_specific_class_is_missing(): void
    {
        $strategy = new PathToClassStrategy('NIH\\Router\\Tests\\Fixtures\\Controllers\\Users');
        [$matched, $path, $routeParams, $class, $method, $allowedMethods] = $this->match($strategy, 'POST', '');

        $this->assertFalse($matched);
        $this->assertSame('', $path);
        $this->assertSame([], $routeParams);
        $this->assertNull($class);
        $this->assertNull($method);
        $this->assertSame([], $allowedMethods);
    }

    public function test_it_returns_no_match_for_empty_segment(): void
    {
        $strategy = new PathToClassStrategy('NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums');
        [$matched] = $this->match($strategy, 'GET', '/view');

        $this->assertFalse($matched);
    }

    public function test_it_returns_no_match_for_non_alnum_segment(): void
    {
        $strategy = new PathToClassStrategy('NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums');
        [$matched] = $this->match($strategy, 'GET', 'view-post');

        $this->assertFalse($matched);
    }

    public function test_it_generates_terminal_path_from_remainder(): void
    {
        $strategy = new PathToClassStrategy('NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums');
        $prefix = '';
        $path = 'list/view';
        $queryParams = [];

        $result = $strategy->generate($prefix, $path, $queryParams);

        $this->assertTrue($result);
        $this->assertSame('list/view', $prefix);
        $this->assertSame('', $path);
        $this->assertSame([], $queryParams);
    }

    public function test_it_accepts_a_trailing_slash_sentinel_during_generation(): void
    {
        $strategy = new PathToClassStrategy('NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums');
        $prefix = '';
        $path = '/';
        $queryParams = [];

        $result = $strategy->generate($prefix, $path, $queryParams);

        $this->assertTrue($result);
        $this->assertSame('', $prefix);
        $this->assertSame('/', $path);
        $this->assertSame([], $queryParams);
    }

    public function test_it_generates_a_trailing_slash_as_part_of_the_terminal_path(): void
    {
        $strategy = new PathToClassStrategy('NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums');
        $prefix = '';
        $path = 'list/';
        $queryParams = [];

        $result = $strategy->generate($prefix, $path, $queryParams);

        $this->assertTrue($result);
        $this->assertSame('list/', $prefix);
        $this->assertSame('', $path);
        $this->assertSame([], $queryParams);
    }

    public function test_it_rejects_numeric_leading_segments_during_generation(): void
    {
        $strategy = new PathToClassStrategy('NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums');
        $prefix = '';
        $path = '123/view';
        $queryParams = [];

        $result = $strategy->generate($prefix, $path, $queryParams);

        $this->assertFalse($result);
        $this->assertSame('', $prefix);
        $this->assertSame('123/view', $path);
        $this->assertSame([], $queryParams);
    }

    public function test_it_rejects_a_leading_slash_during_generation(): void
    {
        $strategy = new PathToClassStrategy('NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums');
        $prefix = '';
        $path = '/view';
        $queryParams = [];

        $result = $strategy->generate($prefix, $path, $queryParams);

        $this->assertFalse($result);
        $this->assertSame('', $prefix);
        $this->assertSame('/view', $path);
        $this->assertSame([], $queryParams);
    }

    public function test_it_rejects_repeated_slashes_during_generation(): void
    {
        $strategy = new PathToClassStrategy('NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums');
        $prefix = '';
        $path = 'view//list';
        $queryParams = [];

        $result = $strategy->generate($prefix, $path, $queryParams);

        $this->assertFalse($result);
        $this->assertSame('', $prefix);
        $this->assertSame('view//list', $path);
        $this->assertSame([], $queryParams);
    }

    public function test_it_rejects_namespace_with_leading_or_trailing_backslashes(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not start or end');

        new PathToClassStrategy('\\NIH\\Router\\Tests\\Fixtures\\Controllers\\Forums\\');
    }

    /**
     * @return array{0: bool, 1: string, 2: array<string, mixed>, 3: ?string, 4: ?string, 5: array<string, true>}
     */
    private function match(PathToClassStrategy $strategy, string $httpMethod, string $path, array $routeParams = []): array
    {
        $queryParams = [];
        $class = null;
        $method = null;
        $allowedMethods = [];
        $matched = $strategy->match($httpMethod, $path, $routeParams, $queryParams, $class, $method, $allowedMethods);

        return [$matched, $path, $routeParams, $class, $method, $allowedMethods];
    }
}
