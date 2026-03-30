<?php

declare(strict_types=1);

namespace Myxa\Routing;

use InvalidArgumentException;

/**
 * Immutable route record used by the router during matching and dispatch.
 */
final readonly class RouteDefinition
{
    /**
     * @param list<string> $methods
     * @param mixed $handler
     */
    public function __construct(
        private array $methods,
        private string $path,
        private mixed $handler,
    ) {
        $parameterNames = $this->parameterNames();

        if (count($parameterNames) !== count(array_unique($parameterNames))) {
            throw new InvalidArgumentException(sprintf(
                'Route path [%s] contains duplicate parameter names.',
                $this->path,
            ));
        }
    }

    /**
     * Return the allowed HTTP methods for this route.
     *
     * @return list<string>
     */
    public function methods(): array
    {
        return $this->methods;
    }

    /**
     * Return the normalized path for this route.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Return the route handler.
     *
     * @return mixed
     */
    public function handler(): mixed
    {
        return $this->handler;
    }

    /**
     * Determine whether this route matches the provided path.
     */
    public function matchesPath(string $path): bool
    {
        return $this->parametersForPath($path) !== null;
    }

    /**
     * Extract route parameters for the provided path when it matches.
     *
     * @return array<string, string>|null
     */
    public function parametersForPath(string $path): ?array
    {
        if ($this->path === $path && $this->parameterNames() === []) {
            return [];
        }

        $pattern = $this->matchPattern();
        if (!preg_match($pattern, $path, $matches)) {
            return null;
        }

        $parameters = [];

        foreach ($this->parameterNames() as $name) {
            if (!array_key_exists($name, $matches) || !is_string($matches[$name])) {
                return null;
            }

            $parameters[$name] = $matches[$name];
        }

        return $parameters;
    }

    /**
     * Determine whether this route accepts the provided HTTP method.
     */
    public function allowsMethod(string $method): bool
    {
        return in_array($method, $this->methods, true);
    }

    /**
     * Return the ordered list of route parameter names.
     *
     * @return list<string>
     */
    private function parameterNames(): array
    {
        $segments = $this->segments();
        $parameterNames = [];

        foreach ($segments as $segment) {
            if (!preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $segment, $matches)) {
                continue;
            }

            $parameterNames[] = $matches[1];
        }

        return $parameterNames;
    }

    /**
     * Compile this route path into a regex pattern.
     */
    private function matchPattern(): string
    {
        $segments = $this->segments();
        $patternSegments = [];

        foreach ($segments as $segment) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $segment, $matches)) {
                $patternSegments[] = sprintf('(?P<%s>[^/]+)', $matches[1]);
                continue;
            }

            if (str_contains($segment, '{') || str_contains($segment, '}')) {
                throw new InvalidArgumentException(sprintf(
                    'Route path [%s] contains an invalid parameter segment [%s].',
                    $this->path,
                    $segment,
                ));
            }

            $patternSegments[] = preg_quote($segment, '#');
        }

        return '#^/' . implode('/', $patternSegments) . '$#';
    }

    /**
     * Split the route path into normalized segments.
     *
     * @return list<string>
     */
    private function segments(): array
    {
        if ($this->path === '/') {
            return [];
        }

        return explode('/', ltrim($this->path, '/'));
    }
}
