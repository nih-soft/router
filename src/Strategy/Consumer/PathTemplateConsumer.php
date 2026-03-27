<?php

declare(strict_types=1);

namespace NIH\Router\Strategy\Consumer;

use NIH\Router\Strategy\StrategyInterface;

final readonly class PathTemplateConsumer implements StrategyInterface
{
    private readonly string $regex;
    private readonly string $generateTemplate;

    /**
     * @var array<string, string>
     */
    private readonly array $templateAttributes;

    /**
     * @var list<string>
     */
    private readonly array $generateSearch;

    public function __construct(string $pattern)
    {
        $regexBody = str_replace(
            ['\\{', '\\:int\\}', '\\}'],
            ['(?P<', '>\\d+)', '>[^/]+)'],
            preg_quote($pattern, '#'),
        );

        $this->regex = $regexBody;
        $this->generateTemplate = str_replace(':int}', '}', $pattern);

        preg_match_all('~\{([a-zA-Z_][a-zA-Z0-9_]*)(:int)?\}~', $pattern, $matches, PREG_SET_ORDER);

        $attributes = [];
        $generateSearch = [];

        foreach ($matches as $match) {
            if (!isset($attributes[$match[1]])) {
                $generateSearch[] = '{' . $match[1] . '}';
            }

            $attributes[$match[1]] = isset($match[2]) ? 'int' : 'string';
        }

        /** @var array<string, string> $attributes */
        $this->templateAttributes = $attributes;
        $this->generateSearch = $generateSearch;
    }

    public function match(
        string $httpMethod,
        string &$path,
        array &$routeParams,
        array &$queryParams,
        ?string &$class,
        ?string &$method,
        array &$allowedMethods,
    ): bool {
        if (preg_match('#^' . $this->regex . '#', $path, $matches) !== 1) {
            return false;
        }

        $matchedPath = $matches[0] ?? '';

        foreach ($matches as $name => $value) {
            if (is_string($name)) {
                $routeParams[$name] = rawurldecode($value);
            }
        }

        $path = substr($path, strlen($matchedPath));

        return false;
    }

    public function generate(
        string &$prefix,
        string &$path,
        array &$queryParams,
    ): bool {
        $replace = [];

        foreach ($this->templateAttributes as $attribute => $type) {
            $value = $queryParams[$attribute] ?? null;

            if ($value === null || is_array($value) || (is_object($value) && !$value instanceof \Stringable)) {
                return false;
            }

            $stringValue = (string) $value;

            if ($stringValue === '') {
                return false;
            }

            $replace[] = $type === 'int'
                ? (string) (int) $value
                : rawurlencode($stringValue);

            unset($queryParams[$attribute]);
        }

        $generatedPath = str_replace($this->generateSearch, $replace, $this->generateTemplate);

        $prefix = $prefix === ''
            ? $generatedPath
            : $prefix . '/' . $generatedPath;

        return false;
    }
}
