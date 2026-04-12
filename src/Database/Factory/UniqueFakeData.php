<?php

declare(strict_types=1);

namespace Myxa\Database\Factory;

use BadMethodCallException;

/**
 * Proxy that enforces uniqueness for fake data generator calls.
 */
final class UniqueFakeData
{
    public function __construct(
        private readonly FakeData $faker,
        private readonly ?string $scope = null,
        private readonly int $maxAttempts = 1000,
    ) {
    }

    /**
     * Generate a unique value from a custom callback.
     */
    public function value(callable $generator, ?string $scope = null): mixed
    {
        return $this->faker->uniqueValue($generator, $scope ?? $this->scope, $this->maxAttempts);
    }

    /**
     * Forward generator methods to the base faker and enforce uniqueness.
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (!method_exists($this->faker, $name) || $name === 'unique') {
            throw new BadMethodCallException(sprintf('Fake data method "%s" is not supported.', $name));
        }

        return $this->faker->uniqueValue(
            fn (): mixed => $this->faker->{$name}(...$arguments),
            $this->scope ?? $name,
            $this->maxAttempts,
        );
    }
}
