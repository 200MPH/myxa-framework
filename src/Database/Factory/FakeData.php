<?php

declare(strict_types=1);

namespace Myxa\Database\Factory;

use BadMethodCallException;
use InvalidArgumentException;
use Random\Randomizer;

/**
 * Lightweight fake data generator used by model factories.
 */
final class FakeData
{
    /** @var array<string, array<string, true>> */
    private array $uniqueValues = [];

    public function __construct(
        private readonly Randomizer $random = new Randomizer(),
    ) {
    }

    /**
     * Return a proxy that forces the next generator calls to produce unique values.
     */
    public function unique(?string $scope = null, int $maxAttempts = 1000): UniqueFakeData
    {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('Unique max attempts must be at least 1.');
        }

        return new UniqueFakeData($this, $scope, $maxAttempts);
    }

    /**
     * Generate a unique value from a custom callback.
     */
    public function uniqueValue(callable $generator, ?string $scope = null, int $maxAttempts = 1000): mixed
    {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('Unique max attempts must be at least 1.');
        }

        $scope ??= 'default';

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $value = $generator();
            $fingerprint = $this->fingerprint($value);

            if (!isset($this->uniqueValues[$scope][$fingerprint])) {
                $this->uniqueValues[$scope][$fingerprint] = true;

                return $value;
            }
        }

        throw new BadMethodCallException(sprintf(
            'Unable to generate a unique fake value for scope "%s" after %d attempts.',
            $scope,
            $maxAttempts,
        ));
    }

    /**
     * Clear tracked unique values.
     */
    public function resetUnique(?string $scope = null): self
    {
        if ($scope === null) {
            $this->uniqueValues = [];

            return $this;
        }

        unset($this->uniqueValues[$scope]);

        return $this;
    }

    /**
     * Generate a random alphanumeric string.
     */
    public function string(int $length = 16): string
    {
        return $this->characters($length, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');
    }

    /**
     * Generate a random alphabetic string.
     */
    public function alpha(int $length = 12): string
    {
        return $this->characters($length, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ');
    }

    /**
     * Generate a random numeric string.
     */
    public function digits(int $length = 6): string
    {
        return $this->characters($length, '0123456789');
    }

    /**
     * Generate a random integer inside the given inclusive bounds.
     */
    public function number(int $min = 0, int $max = 100): int
    {
        if ($min > $max) {
            throw new InvalidArgumentException('Fake number minimum cannot be greater than maximum.');
        }

        return $this->random->getInt($min, $max);
    }

    /**
     * Generate a random float inside the given inclusive bounds.
     */
    public function decimal(float $min = 0, float $max = 100, int $precision = 2): float
    {
        if ($min > $max) {
            throw new InvalidArgumentException('Fake decimal minimum cannot be greater than maximum.');
        }

        if ($precision < 0) {
            throw new InvalidArgumentException('Fake decimal precision cannot be negative.');
        }

        $multiplier = 10 ** $precision;

        return $this->number((int) round($min * $multiplier), (int) round($max * $multiplier)) / $multiplier;
    }

    /**
     * Generate a random boolean.
     */
    public function boolean(int $truePercentage = 50): bool
    {
        if ($truePercentage < 0 || $truePercentage > 100) {
            throw new InvalidArgumentException('Fake boolean true percentage must be between 0 and 100.');
        }

        return $this->number(1, 100) <= $truePercentage;
    }

    /**
     * Pick one value from a non-empty list.
     *
     * @template TValue
     * @param list<TValue> $values
     * @return TValue
     */
    public function choice(array $values): mixed
    {
        if ($values === []) {
            throw new InvalidArgumentException('Fake choice values cannot be empty.');
        }

        return $values[$this->number(0, count($values) - 1)];
    }

    /**
     * Generate a pseudo-natural word.
     */
    public function word(int $minLength = 3, int $maxLength = 10): string
    {
        $this->assertLengthRange($minLength, $maxLength, 'Fake word');

        $consonants = [
            'b', 'br', 'c', 'ch', 'cl', 'cr', 'd', 'dr', 'f', 'fl', 'g', 'gl', 'gr',
            'h', 'j', 'k', 'kl', 'l', 'm', 'n', 'p', 'ph', 'pl', 'pr', 'qu', 'r',
            's', 'sh', 'sk', 'sl', 'sm', 'sn', 'sp', 'st', 't', 'th', 'tr', 'v',
            'w', 'x', 'y', 'z',
        ];
        $vowels = ['a', 'e', 'i', 'o', 'u', 'ae', 'ai', 'ea', 'ee', 'ie', 'oa', 'oo', 'ou'];

        $word = '';
        $useConsonant = $this->boolean();
        $targetLength = $this->number($minLength, $maxLength);

        while (strlen($word) < $targetLength) {
            $pool = $useConsonant ? $consonants : $vowels;
            $chunk = $this->choice($pool);

            if (strlen($word . $chunk) > $targetLength) {
                $chunk = substr($chunk, 0, $targetLength - strlen($word));
            }

            if ($chunk === '') {
                break;
            }

            $word .= $chunk;
            $useConsonant = !$useConsonant;
        }

        return strtolower($word);
    }

    /**
     * Generate several pseudo-natural words.
     *
     * @return list<string>
     */
    public function words(int $count = 3, int $minLength = 3, int $maxLength = 10): array
    {
        if ($count < 1) {
            throw new InvalidArgumentException('Fake words count must be at least 1.');
        }

        $words = [];

        for ($index = 0; $index < $count; $index++) {
            $words[] = $this->word($minLength, $maxLength);
        }

        return $words;
    }

    /**
     * Generate a sentence with a trailing period.
     */
    public function sentence(int $minWords = 4, int $maxWords = 9): string
    {
        $this->assertLengthRange($minWords, $maxWords, 'Fake sentence word');

        $sentence = implode(' ', $this->words($this->number($minWords, $maxWords)));

        return ucfirst($sentence) . '.';
    }

    /**
     * Generate a small paragraph.
     */
    public function paragraph(int $sentences = 3): string
    {
        if ($sentences < 1) {
            throw new InvalidArgumentException('Fake paragraph sentence count must be at least 1.');
        }

        $paragraph = [];

        for ($index = 0; $index < $sentences; $index++) {
            $paragraph[] = $this->sentence();
        }

        return implode(' ', $paragraph);
    }

    /**
     * Generate a simple email address.
     */
    public function email(string $domain = 'example.test'): string
    {
        $domain = trim(strtolower($domain));
        if ($domain === '') {
            throw new InvalidArgumentException('Fake email domain cannot be empty.');
        }

        return sprintf(
            '%s@%s',
            strtolower($this->slug(2, '.')) . $this->digits(3),
            $domain,
        );
    }

    /**
     * Generate a slug from random words.
     */
    public function slug(int $words = 3, string $separator = '-'): string
    {
        if ($words < 1) {
            throw new InvalidArgumentException('Fake slug word count must be at least 1.');
        }

        $separator = trim($separator);
        if ($separator === '') {
            throw new InvalidArgumentException('Fake slug separator cannot be empty.');
        }

        return implode($separator, $this->words($words, 3, 8));
    }

    private function characters(int $length, string $alphabet): string
    {
        if ($length < 1) {
            throw new InvalidArgumentException('Fake string length must be at least 1.');
        }

        if ($alphabet === '') {
            throw new InvalidArgumentException('Fake alphabet cannot be empty.');
        }

        $characters = '';
        $lastIndex = strlen($alphabet) - 1;

        for ($index = 0; $index < $length; $index++) {
            $characters .= $alphabet[$this->random->getInt(0, $lastIndex)];
        }

        return $characters;
    }

    private function assertLengthRange(int $min, int $max, string $label): void
    {
        if ($min < 1 || $max < 1) {
            throw new InvalidArgumentException(sprintf('%s length must be at least 1.', $label));
        }

        if ($min > $max) {
            throw new InvalidArgumentException(sprintf('%s minimum cannot be greater than maximum.', $label));
        }
    }

    private function fingerprint(mixed $value): string
    {
        if (is_object($value)) {
            return sprintf('object:%s:%s', $value::class, serialize($value));
        }

        if (is_array($value)) {
            return 'array:' . serialize($value);
        }

        return sprintf('%s:%s', get_debug_type($value), var_export($value, true));
    }
}
