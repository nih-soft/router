<?php

declare(strict_types=1);

namespace NIH\Router\Tests;

use NIH\Router\Strategy\Consumer\SegmentSlugIdConsumer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SegmentSlugIdConsumerTest extends TestCase
{
    public function test_it_matches_only_digits_id_by_default(): void
    {
        $consumer = new SegmentSlugIdConsumer();
        [$matched, $path, $routeParams, $class, $method, $allowedMethods] = $this->match(
            $consumer,
            'GET',
            '1234/threads/view',
            ['scope' => 'blogs'],
        );

        $this->assertFalse($matched);
        $this->assertSame('threads/view', $path);
        $this->assertSame(
            [
                'scope' => 'blogs',
                'id' => 1234,
            ],
            $routeParams,
        );
        $this->assertNull($class);
        $this->assertNull($method);
        $this->assertSame([], $allowedMethods);
    }

    public function test_it_leaves_non_digit_segments_untouched_by_default(): void
    {
        $consumer = new SegmentSlugIdConsumer();
        [$matched, $path, $routeParams] = $this->match($consumer, 'GET', 'some_text.1234/threads/view');

        $this->assertFalse($matched);
        $this->assertSame('some_text.1234/threads/view', $path);
        $this->assertSame([], $routeParams);
    }

    public function test_it_matches_title_and_id_when_title_param_is_configured(): void
    {
        $consumer = new SegmentSlugIdConsumer(title: 'title');
        [, $path, $routeParams] = $this->match($consumer, 'GET', 'some_text.1234/threads/view');

        $this->assertSame('threads/view', $path);
        $this->assertSame(
            [
                'id' => 1234,
                'title' => 'some_text',
            ],
            $routeParams,
        );
    }

    public function test_it_rejects_invalid_separator_forms(): void
    {
        $consumer = new SegmentSlugIdConsumer(title: 'title');
        [$matchedOne, $pathOne, $routeParamsOne] = $this->match($consumer, 'GET', 'some_text.12.34');
        [$matchedTwo, $pathTwo, $routeParamsTwo] = $this->match($consumer, 'GET', 'some_text.');
        [$matchedThree, $pathThree, $routeParamsThree] = $this->match($consumer, 'GET', 'some_text');

        $this->assertFalse($matchedOne);
        $this->assertSame('some_text.12.34', $pathOne);
        $this->assertSame([], $routeParamsOne);

        $this->assertFalse($matchedTwo);
        $this->assertSame('some_text.', $pathTwo);
        $this->assertSame([], $routeParamsTwo);

        $this->assertFalse($matchedThree);
        $this->assertSame('some_text', $pathThree);
        $this->assertSame([], $routeParamsThree);
    }

    public function test_it_matches_id_only_segments_and_writes_empty_title_when_enabled(): void
    {
        $defaultConsumer = new SegmentSlugIdConsumer();
        [$matchedDefault, $pathDefault, $routeParamsDefault] = $this->match($defaultConsumer, 'GET', '1234/threads/view');

        $consumerWithTitle = new SegmentSlugIdConsumer(title: 'title');
        [$matchedWithTitle, $pathWithTitle, $routeParamsWithTitle] = $this->match($consumerWithTitle, 'GET', '1234/threads/view');
        [$matchedLeadingSeparator, $pathLeadingSeparator, $routeParamsLeadingSeparator] = $this->match($consumerWithTitle, 'GET', '.1234/threads/view');

        $this->assertFalse($matchedDefault);
        $this->assertSame('threads/view', $pathDefault);
        $this->assertSame(['id' => 1234], $routeParamsDefault);

        $this->assertFalse($matchedWithTitle);
        $this->assertSame('threads/view', $pathWithTitle);
        $this->assertSame(['id' => 1234, 'title' => ''], $routeParamsWithTitle);

        $this->assertFalse($matchedLeadingSeparator);
        $this->assertSame('threads/view', $pathLeadingSeparator);
        $this->assertSame(['id' => 1234, 'title' => ''], $routeParamsLeadingSeparator);
    }

    public function test_it_generates_id_only_by_default_and_leaves_unconfigured_title_untouched(): void
    {
        $consumer = new SegmentSlugIdConsumer();
        $prefix = '';
        $path = 'threads/view';
        $queryParams = [
            'title' => 'some_text',
            'id' => 1234,
            'page' => 2,
        ];

        $result = $consumer->generate($prefix, $path, $queryParams);

        $this->assertFalse($result);
        $this->assertSame('1234', $prefix);
        $this->assertSame('threads/view', $path);
        $this->assertSame(
            [
                'title' => 'some_text',
                'page' => 2,
            ],
            $queryParams,
        );
    }

    public function test_it_generates_a_combined_segment_when_title_param_is_configured(): void
    {
        $consumer = new SegmentSlugIdConsumer(title: 'title');
        $prefix = '';
        $path = 'threads/view';
        $queryParams = [
            'title' => 'some_text',
            'id' => 1234,
            'page' => 2,
        ];

        $result = $consumer->generate($prefix, $path, $queryParams);

        $this->assertFalse($result);
        $this->assertSame('some_text.1234', $prefix);
        $this->assertSame('threads/view', $path);
        $this->assertSame(['page' => 2], $queryParams);
    }

    public function test_it_replaces_separator_occurrences_in_title_during_generation(): void
    {
        $consumer = new SegmentSlugIdConsumer(title: 'title');
        $prefix = '';
        $path = 'threads/view';
        $queryParams = [
            'title' => 'some.text',
            'id' => 1234,
        ];

        $this->assertFalse($consumer->generate($prefix, $path, $queryParams));
        $this->assertSame('some_text.1234', $prefix);
        $this->assertSame([], $queryParams);
    }

    public function test_it_requires_id_for_generation(): void
    {
        $consumer = new SegmentSlugIdConsumer('articleId', 'slug');

        $prefix = '';
        $path = 'view';
        $queryParams = [
            'slug' => 'some_text',
        ];

        $this->assertFalse($consumer->generate($prefix, $path, $queryParams));
        $this->assertSame('', $prefix);
        $this->assertSame(['slug' => 'some_text'], $queryParams);
    }

    public function test_it_supports_a_custom_separator(): void
    {
        $consumer = new SegmentSlugIdConsumer(title: 'title', separator: '--');
        [$matched, $path, $routeParams] = $this->match($consumer, 'GET', 'some_text--1234/threads/view');

        $this->assertFalse($matched);
        $this->assertSame('threads/view', $path);
        $this->assertSame(['id' => 1234, 'title' => 'some_text'], $routeParams);

        $prefix = '';
        $remainingPath = 'threads/view';
        $queryParams = [
            'title' => 'some_text',
            'id' => 1234,
        ];

        $this->assertFalse($consumer->generate($prefix, $remainingPath, $queryParams));
        $this->assertSame('some_text--1234', $prefix);
        $this->assertSame([], $queryParams);

        $prefix = '';
        $remainingPath = 'threads/view';
        $queryParams = [
            'title' => 'some--text',
            'id' => 1234,
        ];

        $this->assertFalse($consumer->generate($prefix, $remainingPath, $queryParams));
        $this->assertSame('some_text--1234', $prefix);
        $this->assertSame([], $queryParams);
    }

    public function test_it_rejects_invalid_constructor_arguments(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-empty "id"');

        new SegmentSlugIdConsumer('');
    }

    public function test_it_rejects_empty_title_name(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-empty "title"');

        new SegmentSlugIdConsumer('id', '');
    }

    public function test_it_rejects_duplicate_parameter_names(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different "title" and "id"');

        new SegmentSlugIdConsumer('slug', 'slug');
    }

    public function test_it_rejects_empty_separator(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-empty separator');

        new SegmentSlugIdConsumer('id', 'title', '');
    }

    /**
     * @return array{0: bool, 1: string, 2: array<string, mixed>, 3: ?string, 4: ?string, 5: array<string, true>}
     */
    private function match(SegmentSlugIdConsumer $consumer, string $httpMethod, string $path, array $routeParams = []): array
    {
        $queryParams = [];
        $class = null;
        $method = null;
        $allowedMethods = [];
        $matched = $consumer->match($httpMethod, $path, $routeParams, $queryParams, $class, $method, $allowedMethods);

        return [$matched, $path, $routeParams, $class, $method, $allowedMethods];
    }
}
