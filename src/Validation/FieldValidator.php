<?php

declare(strict_types=1);

namespace Myxa\Validation;

use InvalidArgumentException;
use Myxa\Database\Model\Model;
use Myxa\Mongo\MongoModel;

final class FieldValidator
{
    /** @var list<callable(mixed, string): string|null> */
    private array $rules = [];

    private bool $required = false;

    private bool $nullable = false;

    /**
     * @var callable(mixed, string): string|string|null
     */
    private mixed $requiredMessage = null;

    public function __construct(
        private readonly Validator $validator,
        private readonly string $field,
    ) {
    }

    /**
     * Require the field to be present.
     */
    public function required(string|callable|null $message = null): self
    {
        $this->required = true;
        $this->requiredMessage = $message;
        $this->rules[] = function (mixed $value, string $field) use ($message): ?string {
            if ($value === null) {
                return $this->formatMessage($message, sprintf('The %s field is required.', $field), $value, $field);
            }

            if (is_string($value) && trim($value) === '') {
                return $this->formatMessage($message, sprintf('The %s field is required.', $field), $value, $field);
            }

            if (is_array($value) && $value === []) {
                return $this->formatMessage($message, sprintf('The %s field is required.', $field), $value, $field);
            }

            return null;
        };

        return $this;
    }

    /**
     * Allow the field to be null.
     */
    public function nullable(): self
    {
        $this->nullable = true;

        return $this;
    }

    /**
     * Require the field value to be a string.
     */
    public function string(string|callable|null $message = null): self
    {
        $this->rules[] = fn (mixed $value, string $field): ?string => is_string($value)
            ? null
            : $this->formatMessage($message, sprintf('The %s field must be a string.', $field), $value, $field);

        return $this;
    }

    /**
     * Require the field value to be an integer.
     */
    public function integer(string|callable|null $message = null): self
    {
        $this->rules[] = fn (mixed $value, string $field): ?string => is_int($value)
            ? null
            : $this->formatMessage($message, sprintf('The %s field must be an integer.', $field), $value, $field);

        return $this;
    }

    /**
     * Require the field value to be numeric.
     */
    public function numeric(string|callable|null $message = null): self
    {
        $this->rules[] = fn (mixed $value, string $field): ?string => is_numeric($value)
            ? null
            : $this->formatMessage($message, sprintf('The %s field must be numeric.', $field), $value, $field);

        return $this;
    }

    /**
     * Require the field value to be a boolean.
     */
    public function boolean(string|callable|null $message = null): self
    {
        $this->rules[] = fn (mixed $value, string $field): ?string => is_bool($value)
            ? null
            : $this->formatMessage($message, sprintf('The %s field must be a boolean.', $field), $value, $field);

        return $this;
    }

    /**
     * Require the field value to be an array.
     */
    public function array(string|callable|null $message = null): self
    {
        $this->rules[] = fn (mixed $value, string $field): ?string => is_array($value)
            ? null
            : $this->formatMessage($message, sprintf('The %s field must be an array.', $field), $value, $field);

        return $this;
    }

    /**
     * Require the field value to be a valid email address.
     */
    public function email(string|callable|null $message = null): self
    {
        $this->rules[] = fn (mixed $value, string $field): ?string => is_string($value)
            && filter_var($value, FILTER_VALIDATE_EMAIL) !== false
                ? null
                : $this->formatMessage(
                    $message,
                    sprintf('The %s field must be a valid email address.', $field),
                    $value,
                    $field,
                );

        return $this;
    }

    /**
     * Require the field value to have at least the given size.
     *
     * Strings use length, arrays use item count, and numeric values use their numeric value.
     */
    public function min(int|float $minimum, string|callable|null $message = null): self
    {
        $this->rules[] = function (mixed $value, string $field) use ($minimum, $message): ?string {
            $size = self::sizeOf($value);

            return $size !== null && $size >= $minimum
                ? null
                : $this->formatMessage(
                    $message,
                    sprintf('The %s field must be at least %s.', $field, (string) $minimum),
                    $value,
                    $field,
                );
        };

        return $this;
    }

    /**
     * Require the field value to be no larger than the given size.
     *
     * Strings use length, arrays use item count, and numeric values use their numeric value.
     */
    public function max(int|float $maximum, string|callable|null $message = null): self
    {
        $this->rules[] = function (mixed $value, string $field) use ($maximum, $message): ?string {
            $size = self::sizeOf($value);

            return $size !== null && $size <= $maximum
                ? null
                : $this->formatMessage(
                    $message,
                    sprintf('The %s field must be at most %s.', $field, (string) $maximum),
                    $value,
                    $field,
                );
        };

        return $this;
    }

    /**
     * Require the field value to exist in a source.
     *
     * The source may be:
     * - a SQL model class name
     * - a Mongo model class name
     * - an array of allowed values
     * - a custom callable that returns `true` for existing values
     *
     * @param class-string<Model>|class-string<MongoModel>|array<int, scalar|null>|callable(mixed): bool $source
     */
    public function exists(
        string|array|callable $source,
        ?string $column = null,
        string|callable|null $message = null,
    ): self {
        $this->rules[] = function (mixed $value, string $field) use ($source, $column, $message): ?string {
            return $this->valueExists($value, $source, $column)
                ? null
                : $this->formatMessage($message, sprintf('The selected %s is invalid.', $field), $value, $field);
        };

        return $this;
    }

    /**
     * Evaluate the field against all configured rules.
     *
     * @return list<string>
     */
    public function validate(array $data): array
    {
        $present = array_key_exists($this->field, $data);
        $value = $present ? $data[$this->field] : null;

        if (!$present) {
            return $this->required
                ? [$this->formatMessage(
                    $this->requiredMessage,
                    sprintf('The %s field is required.', $this->field),
                    null,
                    $this->field,
                )]
                : [];
        }

        if ($value === null) {
            if ($this->nullable) {
                return [];
            }

            return $this->required
                ? [$this->formatMessage(
                    $this->requiredMessage,
                    sprintf('The %s field is required.', $this->field),
                    $value,
                    $this->field,
                )]
                : [];
        }

        if ($this->required && is_string($value) && trim($value) === '') {
            return [$this->formatMessage(
                $this->requiredMessage,
                sprintf('The %s field is required.', $this->field),
                $value,
                $this->field,
            )];
        }

        if ($this->required && is_array($value) && $value === []) {
            return [$this->formatMessage(
                $this->requiredMessage,
                sprintf('The %s field is required.', $this->field),
                $value,
                $this->field,
            )];
        }

        $errors = [];

        foreach ($this->rules as $rule) {
            $error = $rule($value, $this->field);
            if (is_string($error)) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * Determine whether this field should be included in validated output.
     */
    public function shouldInclude(array $data): bool
    {
        return array_key_exists($this->field, $data);
    }

    private function valueExists(mixed $value, string|array|callable $source, ?string $column): bool
    {
        if (is_array($source)) {
            return in_array($value, $source, true);
        }

        if (is_callable($source)) {
            return $source($value) === true;
        }

        if (!class_exists($source)) {
            throw new InvalidArgumentException(sprintf('Validation source [%s] is not supported.', $source));
        }

        if (is_subclass_of($source, Model::class)) {
            if ($column === null) {
                return (is_string($value) || is_int($value)) && $source::find($value) !== null;
            }

            return $source::query()->where($column, '=', $value)->first() !== null;
        }

        if (is_subclass_of($source, MongoModel::class)) {
            $lookupColumn = $column ?? $source::primaryKey();

            if ($lookupColumn !== $source::primaryKey()) {
                throw new InvalidArgumentException(sprintf(
                    'Mongo exists validation only supports the primary key [%s].',
                    $source::primaryKey(),
                ));
            }

            return (is_string($value) || is_int($value)) && $source::find($value) !== null;
        }

        throw new InvalidArgumentException(sprintf('Validation source [%s] is not supported.', $source));
    }

    private function formatMessage(
        string|callable|null $message,
        string $default,
        mixed $value,
        string $field,
    ): string {
        if (is_string($message)) {
            return $message;
        }

        if (is_callable($message)) {
            return (string) $message($value, $field);
        }

        return $default;
    }

    private static function sizeOf(mixed $value): int|float|null
    {
        if (is_string($value)) {
            return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        }

        if (is_array($value)) {
            return count($value);
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
