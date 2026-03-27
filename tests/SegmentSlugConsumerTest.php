<?php

declare(strict_types=1);

namespace NIH\Router\Tests;

use NIH\Router\Strategy\Consumer\SegmentSlugConsumer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SegmentSlugConsumerTest extends TestCase
{
    public function test_it_matches_a_non_empty_segment(): void
    {
        $consumer = new SegmentSlugConsumer('slug');
        [$matched, $path, $routeParams] = $this->match($consumer, 'GET', 'some_text/comments/view');

        $this->assertFalse($matched);
        $this->assertSame('comments/view', $path);
        $this->assertSame(['slug' => 'some_text'], $routeParams);
    }

    public function test_it_matches_an_explicit_empty_segment(): void
    {
        $consumer = new SegmentSlugConsumer('slot');
        [$matched, $path, $routeParams] = $this->match($consumer, 'GET', '/comments/view');

        $this->assertFalse($matched);
        $this->assertSame('comments/view', $path);
        $this->assertSame(['slot' => ''], $routeParams);
    }

    public function test_it_rejects_absent_segments(): void
    {
        $consumer = new SegmentSlugConsumer('slug');
        [$matched, $path, $routeParams] = $this->match($consumer, 'GET', '/');

        $this->assertFalse($matched);
        $this->assertSame('/', $path);
        $this->assertSame([], $routeParams);
    }

    public function test_it_generates_from_a_string_and_unsets_the_param(): void
    {
        $consumer = new SegmentSlugConsumer('slug');
        $prefix = '';
        $path = 'comments/view';
        $queryParams = [
            'slug' => 'some text',
            'page' => 2,
        ];

        $result = $consumer->generate($prefix, $path, $queryParams);

        $this->assertFalse($result);
        $this->assertSame('some%20text', $prefix);
        $this->assertSame('comments/view', $path);
        $this->assertSame(['page' => 2], $queryParams);
    }

    public function test_it_can_generate_an_empty_segment_fragment(): void
    {
        $consumer = new SegmentSlugConsumer('slot');
        $prefix = 'pub';
        $path = 'forums/view';
        $queryParams = ['slot' => ''];

        $result = $consumer->generate($prefix, $path, $queryParams);

        $this->assertFalse($result);
        $this->assertSame('pub/', $prefix);
        $this->assertSame('forums/view', $path);
        $this->assertSame([], $queryParams);
    }

    public function test_it_requires_a_non_empty_param_name(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-empty "param" parameter');

        new SegmentSlugConsumer('');
    }

    /**
     * @return array{0: bool, 1: string, 2: array<string, mixed>, 3: ?string, 4: ?string, 5: array<string, true>}
     */
    private function match(SegmentSlugConsumer $consumer, string $httpMethod, string $path, array $routeParams = []): array
    {
        $queryParams = [];
        $class = null;
        $method = null;
        $allowedMethods = [];
        $matched = $consumer->match($httpMethod, $path, $routeParams, $queryParams, $class, $method, $allowedMethods);

        return [$matched, $path, $routeParams, $class, $method, $allowedMethods];
    }
}
