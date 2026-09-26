<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Attributes;

use Attribute;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Enums\EmptySourcePolicy;
use RoundlyConsulting\Sluggable\Enums\LocaleFallback;
use RoundlyConsulting\Sluggable\Enums\LocaleUniqueness;
use RoundlyConsulting\Sluggable\Enums\ManualSlugPolicy;
use RoundlyConsulting\Sluggable\Enums\SlugStorage;
use RoundlyConsulting\Sluggable\Enums\SuffixStrategy;
use RoundlyConsulting\Sluggable\Enums\TargetLocales;
use RoundlyConsulting\Sluggable\Enums\Uniqueness;
use RoundlyConsulting\Sluggable\Enums\UpdatePolicy;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;

/**
 * Declare a slug column on the model class — the closure-free alternative to overriding
 * `slugOptions()`. Repeatable: one attribute per slug column.
 *
 * ```php
 * #[Slug('slug', from: 'name', uniqueWithin: ['kind_id'], routeKey: true)]
 * final class Breed extends Model implements Sluggable { use HasSlug; }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Slug
{
    /**
     * @param  string|list<string>|null  $from
     * @param  list<string>  $uniqueWithin
     * @param  list<string>  $reserved
     */
    public function __construct(
        public string $column = 'slug',
        public string|array|null $from = null,
        public ?string $separator = null,
        public ?int $maxLength = null,
        public ?int $maxWords = null,
        public ?string $language = null,
        public ?bool $unicode = null,
        public ?bool $lowercase = null,
        public ?Uniqueness $uniqueness = null,
        public array $uniqueWithin = [],
        public ?bool $includeTrashed = null,
        public ?LocaleUniqueness $localeUniqueness = null,
        public ?UpdatePolicy $onUpdate = null,
        public ?ManualSlugPolicy $manual = null,
        public ?EmptySourcePolicy $emptySource = null,
        public ?SuffixStrategy $suffix = null,
        public ?int $suffixStart = null,
        public ?int $randomLength = null,
        public ?SlugStorage $storage = null,
        public ?TargetLocales $locales = null,
        public ?LocaleFallback $fallback = null,
        public bool $routeKey = false,
        public bool $bindByKeyFallback = false,
        public ?bool $keepHistory = null,
        public ?bool $redirectFromHistory = null,
        public array $reserved = [],
    ) {}

    public function toDefinition(): SlugDefinition
    {
        $definition = SlugDefinition::for($this->column);

        if ($this->from !== null) {
            $definition->from($this->from);
        }

        $this->applyFormatting($definition);
        $this->applyUniqueness($definition);
        $this->applyLifecycle($definition);

        if ($this->storage !== null) {
            $definition->storage($this->storage);
        }

        if ($this->locales !== null) {
            $definition->locales($this->locales);
        }

        if ($this->fallback !== null) {
            $definition->fallback($this->fallback);
        }

        if ($this->routeKey) {
            $definition->routeKey();
        }

        if ($this->bindByKeyFallback) {
            $definition->bindByKeyFallback();
        }

        if ($this->keepHistory !== null) {
            $definition->keepHistory($this->keepHistory);
        }

        if ($this->redirectFromHistory !== null) {
            $definition->redirectFromHistory($this->redirectFromHistory);
        }

        return $definition;
    }

    private function applyFormatting(SlugDefinition $definition): void
    {
        if ($this->separator !== null) {
            $definition->separator($this->separator);
        }

        if ($this->maxLength !== null) {
            $definition->maxLength($this->maxLength);
        }

        if ($this->maxWords !== null) {
            $definition->maxWords($this->maxWords);
        }

        if ($this->language !== null) {
            $definition->language($this->language);
        }

        if ($this->unicode !== null) {
            $definition->unicode($this->unicode);
        }

        if ($this->lowercase !== null) {
            $definition->lowercase($this->lowercase);
        }

        if ($this->reserved !== []) {
            $definition->reserved($this->reserved);
        }
    }

    private function applyUniqueness(SlugDefinition $definition): void
    {
        match (true) {
            $this->uniqueness === Uniqueness::None => $definition->notUnique(),
            $this->uniqueness === Uniqueness::Global => $definition->unique(),
            $this->uniqueness === Uniqueness::Scoped, $this->uniqueWithin !== [] => $definition->uniqueWithin(...$this->uniqueWithin),
            default => null,
        };

        if ($this->includeTrashed !== null) {
            $definition->includeTrashed($this->includeTrashed);
        }

        match ($this->localeUniqueness) {
            LocaleUniqueness::PerLocale => $definition->perLocaleUniqueness(),
            LocaleUniqueness::AcrossLocales => $definition->uniqueAcrossLocales(),
            null => null,
        };

        match ($this->suffix) {
            SuffixStrategy::Sequential => $definition->sequentialSuffix($this->suffixStart ?? 2),
            SuffixStrategy::Random => $definition->randomSuffix(),
            // A custom generator is a closure or object, which attribute arguments cannot hold.
            SuffixStrategy::Custom => throw InvalidSlugDefinitionException::missingSuffixGenerator($this->column),
            null => $this->suffixStart !== null ? $definition->sequentialSuffix($this->suffixStart) : null,
        };

        if ($this->randomLength !== null) {
            $definition->randomLength($this->randomLength);
        }
    }

    private function applyLifecycle(SlugDefinition $definition): void
    {
        if ($this->onUpdate !== null) {
            $definition->onUpdate($this->onUpdate);
        }

        if ($this->manual !== null) {
            $definition->manual($this->manual);
        }

        if ($this->emptySource !== null) {
            $definition->whenEmptySource($this->emptySource);
        }
    }
}
