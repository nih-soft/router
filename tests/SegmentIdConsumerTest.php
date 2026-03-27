<?php

declare(strict_types=1);

namespace NIH\Router\Tests;

use NIH\Router\Strategy\Consumer\SegmentIdConsumer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SegmentIdConsumerTest extends TestCase
{
    public function test_it_matches_a_non_empty_digits_only_segment(): void
    {
        $consumer = new SegmentIdConsumer('id');
        [$matched, $path, $routeParams] = $this->match($consumer, 'GET', '123/comments/view');

        $this->assertFalse($matched);
        $this->assertSame('comments/view', $path);
        $this->assertSame(['id' => '123'], $routeParams);
    }

    public function test_it_rejects_an_empty_segment(): void
    {
        $consumer = new SegmentIdConsumer('id');
        [$matched, $path, $routeParams] = $this->match($consumer, 'GET', '/comments/view');

        $this->assertFalse($matched);
        $this->assertSame('/comments/view', $path);
        $this->assertSame([], $routeParams);
    }

    public function test_it_rejects_a_non_digit_segment(): void
    {
        $consumer = new SegmentIdConsumer('id');
        [$matched, $path, $routeParams] = $this->match($consumer, 'GET', 'abc/comments/view');

        $this->assertFalse($matched);
        $this->assertSame('abc/comments/view', $path);
        $this->assertSame([], $routeParams);
    }

    public function test_it_generates_from_a_digit_string_and_unsets_the_param(): void
    {
        $consumer = new SegmentIdConsumer('id');
        $prefix = '';
        $path = 'comments/view';
        $queryParams = [
            'id' => '123',
            'page' => 2,
        ];

        $result = $consumer->generate($prefix, $path, $queryParams);

        $this->assertFalse($result);
        $this->assertSame('123', $prefix);
        $this->assertSame('comments/view', $path);
        $this->assertSame(['page' => 2], $queryParams);
    }

    public function test_it_rejects_empty_generation_values_and_casts_any_non_empty_value(): void
    {
        $consumer = new SegmentIdConsumer('id');

        $prefix = '';
        $path = 'comments/view';
        $queryParams = ['id' => ''];

        $this->assertFalse($consumer->generate($prefix, $path, $queryParams));
        $this->assertSame('', $prefix);
        $this->assertSame(['id' => ''], $queryParams);

        $prefix = '';
        $path = 'comments/view';
        $queryParams = ['id' => 'abc'];

        $this->assertFalse($consumer->generate($prefix, $path, $queryParams));
        $this->assertSame('0', $prefix);
        $this->assertSame([], $queryParams);
    }

    public function test_it_requires_a_non_empty_param_name(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-empty "param" parameter');

        new SegmentIdConsumer('');
    }

    /**
     * @return array{0: bool, 1: string, 2: array<string, mixed>, 3: ?string, 4: ?string, 5: array<string, true>}
     */
    private function match(SegmentIdConsumer $consumer, string $httpMethod, string $path, array $routeParams = []): array
    {
        $queryParams = [];
        $class = null;
        $method = null;
        $allowedMethods = [];
        $matched = $consumer->match($httpMethod, $path, $routeParams, $queryParams, $class, $method, $allowedMethods);

        return [$matched, $path, $routeParams, $class, $method, $allowedMethods];
    }
}
