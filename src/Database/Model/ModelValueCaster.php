<?php

declare(strict_types=1);

namespace Myxa\Database\Model;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final class ModelValueCaster
{
    public function __construct(
        private readonly ModelMetadata $metadata,
        private readonly string $modelClass,
    ) {
    }

    public function castAttributeValue(string $name, mixed $value): mixed
    {
        $cast = $this->metadata->castForProperty($name);
        if ($cast === null || $value === null) {
            return $value;
        }

        return match ($cast->type) {
            CastType::DateTime => $this->castToDateTime($name, $value, $cast->format, DateTime::class),
            CastType::DateTimeImmutable => $this->castToDateTime(
                $name,
                $value,
                $cast->format,
                DateTimeImmutable::class,
            ),
        };
    }

    public function serializeAttributeValue(string $name, mixed $value): mixed
    {
        if (!$value instanceof DateTimeInterface) {
            return $value;
        }

        $format = $this->metadata->castForProperty($name)?->format ?? DATE_ATOM;

        return $value->format($format);
    }

    /**
     * @param class-string<DateTime|DateTimeImmutable> $dateTimeClass
     */
    private function castToDateTime(
        string $name,
        mixed $value,
        ?string $format,
        string $dateTimeClass,
    ): DateTimeInterface {
        if ($dateTimeClass === DateTimeImmutable::class && $value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($dateTimeClass === DateTime::class && $value instanceof DateTime) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $dateTimeClass === DateTimeImmutable::class
                ? DateTimeImmutable::createFromInterface($value)
                : DateTime::createFromInterface($value);
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'Cannot cast non-string value for property "%s" on model %s to %s.',
                $name,
                $this->modelClass,
                $dateTimeClass,
            ));
        }

        $dateTime = $format !== null
            ? $dateTimeClass::createFromFormat($format, $value)
            : new $dateTimeClass($value);

        if (!$dateTime instanceof DateTimeInterface) {
            throw new InvalidArgumentException(sprintf(
                'Cannot cast value "%s" for property "%s" on model %s to %s.',
                $value,
                $name,
                $this->modelClass,
                $dateTimeClass,
            ));
        }

        $errors = DateTime::getLastErrors();
        if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
            throw new InvalidArgumentException(sprintf(
                'Cannot cast value "%s" for property "%s" on model %s to %s.',
                $value,
                $name,
                $this->modelClass,
                $dateTimeClass,
            ));
        }

        return $dateTime;
    }
}
