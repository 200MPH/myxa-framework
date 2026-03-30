<?php

declare(strict_types=1);

namespace Myxa\Routing;

use RuntimeException;

/**
 * Thrown when a route path exists but does not allow the current method.
 */
final class MethodNotAllowedException extends RuntimeException
{
    /**
     * @param list<string> $allowedMethods
     */
    public function __construct(
        string $method,
        string $path,
        private readonly array $allowedMethods,
    ) {
        parent::__construct(sprintf(
            'Method [%s] is not allowed for route [%s]. Allowed methods: %s.',
            $method,
            $path,
            implode(', ', $allowedMethods),
        ));
    }

    /**
     * Return the methods allowed for the matched path.
     *
     * @return list<string>
     */
    public function allowedMethods(): array
    {
        return $this->allowedMethods;
    }
}
