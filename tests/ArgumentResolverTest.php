<?php

declare(strict_types=1);

namespace NIH\Router\Tests;

use NIH\Router\Middleware\ArgumentResolver;
use NIH\Router\Middleware\Attribute\FromAttribute;
use NIH\Router\Middleware\Attribute\FromQuery;
use NIH\Router\Tests\Fixtures\Http\FakeResponse;
use NIH\Router\Tests\Fixtures\Http\FakeServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;

final class ArgumentResolverTest extends TestCase
{
    public function test_it_exposes_a_non_instantiable_static_api(): void
    {
        $reflection = new ReflectionClass(ArgumentResolver::class);

        self::assertFalse($reflection->isInstantiable());
        self::assertTrue($reflection->getMethod('resolveCallableArguments')->isStatic());
    }

    public function test_it_resolves_runtime_attribute_and_query_arguments_via_static_api(): void
    {
        $request = (new FakeServerRequest('/articles/view', 'GET', queryParams: [
            'page' => '5',
        ]))
            ->withAttribute('id', '42')
            ->withAttribute('slug', 'news');
        $response = new FakeResponse(201);
        $callable = static function (
            ServerRequestInterface $request,
            #[FromAttribute('id')] int $id,
            #[FromQuery('page')] int $page,
            ?ResponseInterface $response = null,
            string $slug,
        ): void {
        };

        $arguments = ArgumentResolver::resolveCallableArguments($callable, $request, $response);

        self::assertSame($request, $arguments['request']);
        self::assertSame(42, $arguments['id']);
        self::assertSame(5, $arguments['page']);
        self::assertSame($response, $arguments['response']);
        self::assertSame('news', $arguments['slug']);
    }

    public function test_it_can_be_reused_across_calls_without_leaking_unresolved_arguments(): void
    {
        $callable = static function (int $page, string $slug): void {
        };

        $firstArguments = ArgumentResolver::resolveCallableArguments(
            $callable,
            new FakeServerRequest('/articles/view', 'GET'),
        );
        $secondArguments = ArgumentResolver::resolveCallableArguments(
            $callable,
            (new FakeServerRequest('/articles/view', 'GET', queryParams: [
                'page' => '7',
            ]))->withAttribute('slug', 'latest'),
        );

        self::assertSame([], $firstArguments);
        self::assertSame([
            'page' => 7,
            'slug' => 'latest',
        ], $secondArguments);
    }
}
