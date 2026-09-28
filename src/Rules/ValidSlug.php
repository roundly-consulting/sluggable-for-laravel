<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugFormat;
use RoundlyConsulting\Sluggable\Support\SluggableConfig;
use RoundlyConsulting\Sluggable\Support\Slugger;
use RoundlyConsulting\Sluggable\Support\SlugState;

/**
 * Passes when the value already is a slug: it equals its own normalisation, fits the length and
 * is not reserved. Arrays (locale-map input) are checked value by value.
 */
final class ValidSlug implements ValidationRule
{
    private SlugFormat $format;

    /** @param list<string> $reserved */
    public function __construct(
        string $separator = '-',
        int $maxLength = 255,
        bool $unicode = false,
        bool $lowercase = true,
        array $reserved = [],
    ) {
        $this->format = new SlugFormat(
            separator: $separator,
            maxLength: $maxLength,
            lowercase: $lowercase,
            unicode: $unicode,
            dictionary: [],
            reserved: array_map(mb_strtolower(...), [...SluggableConfig::reserved(), ...$reserved]),
            maxSourceLength: max($maxLength, SluggableConfig::maxSourceLength()),
        );
    }

    /**
     * The format of a model's slug definition (incl. its reserved words).
     *
     * @param  class-string<Model>  $modelClass
     */
    public static function for(string $modelClass, ?string $column = null): self
    {
        $definition = app(SlugState::class)->options(new $modelClass)->get($column);
        $format = $definition->format;

        $rule = new self($format->separator, $format->maxLength, $format->unicode, $format->lowercase);
        $rule->format = new SlugFormat(
            separator: $format->separator,
            maxLength: $format->maxLength,
            dictionary: [],
            lowercase: $format->lowercase,
            unicode: $format->unicode,
            reserved: $format->reserved,
            maxSourceLength: max($format->maxLength, $format->maxSourceLength),
        );

        return $rule;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $values = is_array($value) ? $value : [$value];

        foreach ($values as $item) {
            if (! is_string($item)) {
                continue;
            }

            if (mb_strlen($item) > $this->format->maxLength) {
                $fail('sluggable::validation.too_long')->translate(['max' => (string) $this->format->maxLength]);

                return;
            }

            if (Slugger::isReserved($item, $this->format)) {
                $fail('sluggable::validation.reserved')->translate();

                return;
            }

            if ($item === '' || ! $this->isNormalized($item)) {
                $fail('sluggable::validation.format')->translate();

                return;
            }
        }
    }

    private function isNormalized(string $value): bool
    {
        // Transliteration is not re-applied: an ASCII slug is already ASCII, and a unicode slug keeps its letters.
        return Slugger::isNormalized($value, $this->format);
    }
}
