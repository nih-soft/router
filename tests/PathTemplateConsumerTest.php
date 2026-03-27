<?php

declare(strict_types=1);

namespace NIH\Router\Tests;

use NIH\Router\Strategy\Consumer\PathTemplateConsumer;
use PHPUnit\Framework\TestCase;

final class PathTemplateConsumerTest extends TestCase
{
    public function test_it_matches_named_placeholders_from_multiple_path_segments(): void
    {
        $consumer = new PathTemplateConsumer('posts/{year:int}/{category}');
        [$matched, $path, $routeParams, $class, $method, $allowedMethods] = $this->match(
            $consumer,
            'GET',
            'posts/2024/php/view',
            ['scope' => 'archive'],
        );

        $this->assertFalse($matched);
        $this->assertSame('/view', $path);
        $this->assertSame(
            [
                'scope' => 'archive',
                'year' => '2024',
                'category' => 'php',
            ],
            $routeParams,
        );
        $this->assertNull($class);
        $this->assertNull($method);
        $this->assertSame([], $allowedMethods);
    }

    public function test_it_preserves_trailing_slash_sentinel_after_consuming_the_match(): void
    {
        $consumer = new PathTemplateConsumer('{title}.{id:int}');
        [, $path, $routeParams] = $this->match($consumer, 'GET', 'some_text.1234/');

        $this->assertSame('/', $path);
        $this->assertSame(
            [
                'title' => 'some_text',
                'id' => '1234',
            ],
            $routeParams,
        );
    }

    public function test_it_consumes_a_trailing_slash_when_it_is_part_of_the_pattern(): void
    {
        $consumer = new PathTemplateConsumer('{blogId:int}/');
        [, $path, $routeParams] = $this->match($consumer, 'GET', '25/threads/view');

        $this->assertSame('threads/view', $path);
        $this->assertSame(['blogId' => '25'], $routeParams);
    }

    public function test_it_can_consume_and_generate_a_literal_path_without_placeholders(): void
    {
        $consumer = new PathTemplateConsumer('posts/archive');
        [$matched, $path, $routeParams] = $this->match($consumer, 'GET', 'posts/archive/view', ['scope' => 'blog']);

        $this->assertFalse($matched);
        $this->assertSame('/view', $path);
        $this->assertSame(['scope' => 'blog'], $routeParams);

        $prefix = '';
        $path = 'view';
        $queryParams = ['page' => 2];

        $result = $consumer->generate($prefix, $path, $queryParams);

        $this->assertFalse($result);
        $this->assertSame('posts/archive', $prefix);
        $this->assertSame('view', $path);
        $this->assertSame(['page' => 2], $queryParams);
    }

    public function test_it_generates_a_fragment_from_the_pattern_by_default(): void
    {
        $consumer = new PathTemplateConsumer('posts/{year:int}/{month:int}');
        $prefix = '';
        $path = 'view';
        $queryParams = [
            'year' => 2024,
            'month' => '03',
            'page' => 2,
        ];

        $result = $consumer->generate($prefix, $path, $queryParams);

        $this->assertFalse($result);
        $this->assertSame('posts/2024/3', $prefix);
        $this->assertSame('view', $path);
        $this->assertSame(['page' => 2], $queryParams);
    }

    public function test_it_casts_int_values_during_generation_instead_of_validating_them(): void
    {
        $consumer = new PathTemplateConsumer('{title}.{id:int}');
        $prefix = '';
        $path = 'comments/view';
        $queryParams = [
            'title' => 'some_text',
            'id' => 'abc',
        ];

        $this->assertFalse($consumer->generate($prefix, $path, $queryParams));
        $this->assertSame('some_text.0', $prefix);
        $this->assertSame([], $queryParams);
    }

    /**
     * @return array{0: bool, 1: string, 2: array<string, mixed>, 3: ?string, 4: ?string, 5: array<string, true>}
     */
    private function match(PathTemplateConsumer $consumer, string $httpMethod, string $path, array $routeParams = []): array
    {
        $queryParams = [];
        $class = null;
        $method = null;
        $allowedMethods = [];
        $matched = $consumer->match($httpMethod, $path, $routeParams, $queryParams, $class, $method, $allowedMethods);

        return [$matched, $path, $routeParams, $class, $method, $allowedMethods];
    }
}
