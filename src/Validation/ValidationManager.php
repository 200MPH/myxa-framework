<?php

declare(strict_types=1);

namespace Myxa\Validation;

final class ValidationManager
{
    /**
     * Create a new fluent validator for the provided input data.
     *
     * @param array<string, mixed> $data
     */
    public function make(array $data): Validator
    {
        return new Validator($data);
    }
}
