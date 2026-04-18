<?php

declare(strict_types=1);

namespace Myxa\Validation;

use Myxa\Validation\Exceptions\ValidationException;

final class Validator
{
    /** @var array<string, FieldValidator> */
    private array $fields = [];

    /** @var array<string, list<string>> */
    private array $errors = [];

    private bool $validated = false;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data)
    {
    }

    /**
     * Start configuring fluent validation rules for a field.
     */
    public function field(string $name): FieldValidator
    {
        return $this->fields[$name] ??= new FieldValidator($this, $name);
    }

    /**
     * Determine whether the validator passes all configured rules.
     */
    public function passes(): bool
    {
        $this->errors = [];

        foreach ($this->fields as $validator) {
            foreach ($validator->validate($this->data) as $field => $errors) {
                if ($errors !== []) {
                    $this->errors[$field] = $errors;
                }
            }
        }

        $this->validated = true;

        return $this->errors === [];
    }

    /**
     * Determine whether the validator has any validation failures.
     */
    public function fails(): bool
    {
        return !$this->passes();
    }

    /**
     * Return the validation errors grouped by field.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        if (!$this->validated) {
            $this->passes();
        }

        return $this->errors;
    }

    /**
     * Validate the data and return the validated subset.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(): array
    {
        if (!$this->passes()) {
            throw new ValidationException($this->errors);
        }

        return $this->validated();
    }

    /**
     * Return the validated subset when validation has already succeeded.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validated(): array
    {
        if (!$this->validated) {
            return $this->validate();
        }

        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }

        $validated = [];

        foreach ($this->fields as $validator) {
            foreach ($validator->validatedValues($this->data) as $field => $value) {
                $this->setValidatedValue($validated, $field, $value);
            }
        }

        return $validated;
    }

    /**
     * @param array<string, mixed> $validated
     */
    private function setValidatedValue(array &$validated, string $field, mixed $value): void
    {
        $segments = explode('.', $field);
        $cursor = &$validated;

        foreach ($segments as $index => $segment) {
            $isLast = $index === count($segments) - 1;

            if ($isLast) {
                $cursor[$segment] = $value;

                return;
            }

            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }
    }
}
