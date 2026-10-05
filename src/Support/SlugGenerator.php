<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Sluggable\Contracts\SlugLocales;
use RoundlyConsulting\Sluggable\DataTransferObjects\ProbeContext;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugSeed;
use RoundlyConsulting\Sluggable\Definitions\ResolvedSlugDefinition;
use RoundlyConsulting\Sluggable\Enums\LocaleUniqueness;
use RoundlyConsulting\Sluggable\Enums\SuffixStrategy;
use RoundlyConsulting\Sluggable\Exceptions\SlugGenerationException;

/**
 * Builds slug values and finds free ones: sources → pipeline → affixes, then bounded, batched
 * candidate probing (base, sequential batches, random attempts, then an exception).
 */
final readonly class SlugGenerator
{
    public function __construct(
        private SourceResolver $sources,
        private UniquenessChecker $checker,
        private SlugLocales $locales,
    ) {}

    /** The slug body from the sources ('' when they produce nothing). */
    public function body(Model $model, ResolvedSlugDefinition $definition, ?string $locale): string
    {
        return Slugger::slugify(
            $this->sources->text($model, $definition, $locale),
            $definition->format,
            $definition->languageFor($locale),
            $locale,
        );
    }

    /** The full candidate for a seed, with an optional collision suffix. */
    public function compose(Model $model, ResolvedSlugDefinition $definition, ?string $locale, SlugSeed $seed, ?string $collision = null): string
    {
        if (! $seed->withAffixes) {
            return Slugger::compose($seed->body, $definition->format, '', '', $collision);
        }

        return Slugger::compose(
            $seed->body,
            $definition->format,
            $this->affix($model, $definition, $definition->prefix, $locale),
            $this->affix($model, $definition, $definition->suffix, $locale),
            $collision,
        );
    }

    /**
     * The base the persisted sources produce — what the stored slug was built from before this
     * save changed them. Read from a clone holding the raw original attributes, so accessors,
     * casts and closure sources see the old values exactly as they saw the new ones.
     */
    public function originalBase(Model $model, ResolvedSlugDefinition $definition, ?string $locale): string
    {
        $original = clone $model;
        $original->setRawAttributes($model->getRawOriginal());

        return $this->compose($original, $definition, $locale, new SlugSeed($this->body($original, $definition, $locale), true));
    }

    /**
     * The first free value for a seed: the bare candidate, then sequential/custom suffixes in
     * batches of `probe_batch`, then random suffixes — then an exception, never an endless loop.
     */
    public function unique(ProbeContext $context, ResolvedSlugDefinition $definition, ?string $locale, SlugSeed $seed): string
    {
        $model = $context->model;
        $base = $this->compose($model, $definition, $locale, $seed);

        if (! $this->checker->isTaken($context, $definition, $locale, $base)) {
            return $base;
        }

        if ($definition->suffixStrategy !== SuffixStrategy::Random) {
            for ($attempt = 1; $attempt <= $definition->sequentialProbes; $attempt += $definition->probeBatch) {
                $batch = [];
                $last = min($attempt + $definition->probeBatch - 1, $definition->sequentialProbes);

                for ($i = $attempt; $i <= $last; $i++) {
                    $suffix = Slugger::collisionSuffix($definition->suffixGenerator->suffix($base, $i, $definition), $definition->format);
                    $batch[] = $this->compose($model, $definition, $locale, $seed, $suffix);
                }

                $taken = $this->checker->taken($context, $definition, $locale, $batch);

                foreach ($batch as $candidate) {
                    if (! in_array($candidate, $taken, true)) {
                        return $candidate;
                    }
                }
            }
        }

        for ($attempt = 1; $attempt <= $definition->randomAttempts; $attempt++) {
            $candidate = $this->compose($model, $definition, $locale, $seed, Slugger::random($definition->randomLength, $definition->format->lowercase));

            if (! $this->checker->isTaken($context, $definition, $locale, $candidate)) {
                return $candidate;
            }
        }

        throw SlugGenerationException::exhausted($model, $definition->column, $locale);
    }

    /**
     * The probe context of a model being saved.
     *
     * @param  array<string, string>  $map  the locale map under construction (across-locales claims)
     */
    public function context(Model $model, ResolvedSlugDefinition $definition, ?string $locale = null, array $map = []): ProbeContext
    {
        $scope = [];

        foreach ($definition->scopeColumns as $column) {
            $scope[$column] = $model->getAttribute($column);
        }

        $claimed = [];
        $locales = [];

        if ($definition->localeUniqueness === LocaleUniqueness::AcrossLocales && $definition->isLocalized()) {
            foreach ($map as $key => $value) {
                if ($key !== $locale) {
                    $claimed[] = $value;
                }
            }

            $locales = array_values(array_unique([...$this->locales->supported(), ...array_keys($map)]));
        }

        return new ProbeContext(
            model: $model,
            ignoreKey: $model->exists ? $model->getKey() : null,
            scope: $scope,
            scopeModel: $model,
            claimed: $claimed,
            locales: $locales,
        );
    }

    private function affix(Model $model, ResolvedSlugDefinition $definition, string|Closure|null $affix, ?string $locale): string
    {
        if ($affix === null) {
            return '';
        }

        $raw = $affix instanceof Closure ? $affix($model, $locale) : $affix;

        if (! is_string($raw) || $raw === '') {
            return '';
        }

        return Slugger::affix($raw, $definition->format, $definition->languageFor($locale));
    }
}
