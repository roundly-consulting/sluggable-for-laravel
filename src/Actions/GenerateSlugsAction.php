<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\DataTransferObjects\ProbeContext;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugChange;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugGenerationRequest;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugGenerationResult;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugSeed;
use RoundlyConsulting\Sluggable\Definitions\ResolvedSlugDefinition;
use RoundlyConsulting\Sluggable\Enums\ChangeReason;
use RoundlyConsulting\Sluggable\Enums\EmptySourcePolicy;
use RoundlyConsulting\Sluggable\Enums\GenerationTrigger;
use RoundlyConsulting\Sluggable\Enums\ManualSlugPolicy;
use RoundlyConsulting\Sluggable\Enums\RegenerationMode;
use RoundlyConsulting\Sluggable\Enums\UpdatePolicy;
use RoundlyConsulting\Sluggable\Exceptions\SlugAlreadyTakenException;
use RoundlyConsulting\Sluggable\Exceptions\SlugGenerationException;
use RoundlyConsulting\Sluggable\Exceptions\SlugLockedException;
use RoundlyConsulting\Sluggable\SlugManager;
use RoundlyConsulting\Sluggable\Support\IdentifierGuard;
use RoundlyConsulting\Sluggable\Support\LocaleMapAccessor;
use RoundlyConsulting\Sluggable\Support\SlugGenerator;
use RoundlyConsulting\Sluggable\Support\Slugger;
use RoundlyConsulting\Sluggable\Support\SlugState;
use RoundlyConsulting\Sluggable\Support\SourceResolver;
use RoundlyConsulting\Sluggable\Support\UniquenessChecker;

/**
 * One generation pass over a model: for every definition (in declaration order), and for every
 * target locale of a locale map, apply the lifecycle rules — manual policy, update policy, locks,
 * empty sources, the no-churn fingerprint, and uniqueness re-checks after a scope change or a
 * restore. Only sets attributes; the caller saves.
 */
final readonly class GenerateSlugsAction
{
    public function __construct(
        private SlugGenerator $generator,
        private SourceResolver $sources,
        private UniquenessChecker $checker,
        private SlugState $state,
        private SlugManager $manager,
    ) {}

    public function execute(SlugGenerationRequest $request): SlugGenerationResult
    {
        $model = $request->model;
        $changes = [];

        foreach ($this->state->options($model)->definitions as $definition) {
            if ($request->columns !== null && ! in_array($definition->column, $request->columns, true)) {
                continue;
            }

            if ($definition->shouldSkip($model)) {
                continue;
            }

            $definition->isLocalized()
                ? $this->localeMap($request, $definition, $changes)
                : $this->string($request, $definition, $changes);
        }

        return new SlugGenerationResult($changes);
    }

    /** @param list<SlugChange> $changes */
    private function string(SlugGenerationRequest $request, ResolvedSlugDefinition $definition, array &$changes): void
    {
        $model = $request->model;
        $column = $definition->column;

        $current = self::stringValue($model->getAttribute($column));
        $original = $model->exists ? self::stringValue($model->getRawOriginal($column)) : null;

        $manual = $current !== null
            && $current !== $this->state->written($model, $column, Sluggable::SINGLE_KEY)
            && match ($request->trigger) {
                GenerationTrigger::Creating => true,
                GenerationTrigger::Restoring => false,
                default => $model->isDirty($column),
            };

        $final = $this->decide($request, $definition, null, $current, $original, $manual, true, [], $changes);

        if ($final === false) {
            $model->setAttribute($column, null);
        } elseif ($final !== null && $final !== $current) {
            $model->setAttribute($column, $final);
        }
    }

    /** @param list<SlugChange> $changes */
    private function localeMap(SlugGenerationRequest $request, ResolvedSlugDefinition $definition, array &$changes): void
    {
        $model = $request->model;
        $column = $definition->column;

        $current = LocaleMapAccessor::read($model, $column);
        $original = $model->exists ? LocaleMapAccessor::original($model, $column) : [];

        $targets = match (true) {
            $request->locales !== null => array_map(IdentifierGuard::locale(...), $request->locales),
            $request->trigger === GenerationTrigger::Restoring => [],
            default => $this->sources->targetLocales($model, $definition),
        };

        // Keys already in the stored map are data: a malformed one (`EN`, `default`) is left
        // untouched rather than blocking every save of the row.
        $locales = $request->locales !== null
            ? $targets
            : array_values(array_unique([...$targets, ...array_filter(array_keys($current), IdentifierGuard::isLocale(...))]));
        $map = $current;

        foreach ($locales as $locale) {
            $value = $current[$locale] ?? null;
            $previous = $original[$locale] ?? null;

            $manual = $value !== null
                && $value !== $this->state->written($model, $column, $locale)
                && $request->trigger !== GenerationTrigger::Restoring
                && ($request->trigger === GenerationTrigger::Creating || $value !== $previous);

            $final = $this->decide($request, $definition, $locale, $value, $previous, $manual, in_array($locale, $targets, true), $map, $changes);

            if ($final === false) {
                unset($map[$locale]);
            } elseif ($final !== null) {
                $map[$locale] = $final;
            }
        }

        if ($map !== $current) {
            LocaleMapAccessor::write($model, $column, $map);
        }
    }

    /**
     * The final value for one column/locale: a string to write, null to keep what is there, or
     * false to clear a discarded manual value.
     *
     * @param  array<string, string>  $map
     * @param  list<SlugChange>  $changes
     */
    private function decide(
        SlugGenerationRequest $request,
        ResolvedSlugDefinition $definition,
        ?string $locale,
        ?string $current,
        ?string $previous,
        bool $manual,
        bool $isTarget,
        array $map,
        array &$changes,
    ): string|false|null {
        $model = $request->model;
        $locked = $model->exists
            && ! $request->force
            && ! $this->manager->isUnlocked()
            && $definition->isLockedFor($model);

        if ($manual && $current !== null) {
            if ($locked) {
                throw SlugLockedException::forColumn($model, $definition->column, $locale);
            }

            $context = $this->generator->context($model, $definition, $locale, $map);
            $seed = $this->manualSeed($context, $definition, $locale, $current);

            if ($seed !== null) {
                $value = $definition->manual === ManualSlugPolicy::Strict
                    ? $current
                    : $this->generator->unique($context, $definition, $locale, $seed);

                return $this->record($model, $definition, $locale, $previous, $value, ChangeReason::Manual, $seed, $changes);
            }

            // A manual value that normalises to nothing is treated as no value at all: generated
            // when the policy fills an empty value, otherwise cleared — its raw bytes (`?#/`, `%`)
            // never reach the column.
            return $this->automatic($request, $definition, $locale, null, $previous, $locked, $isTarget, $map, $changes) ?? false;
        }

        return $this->automatic($request, $definition, $locale, $current, $previous, $locked, $isTarget, $map, $changes);
    }

    /**
     * The automatic part of {@see self::decide()}: generation, the no-churn fingerprint and the
     * uniqueness re-checks. Null keeps what is there.
     *
     * @param  array<string, string>  $map
     * @param  list<SlugChange>  $changes
     */
    private function automatic(
        SlugGenerationRequest $request,
        ResolvedSlugDefinition $definition,
        ?string $locale,
        ?string $current,
        ?string $previous,
        bool $locked,
        bool $isTarget,
        array $map,
        array &$changes,
    ): ?string {
        $model = $request->model;

        if ($locked || ! $isTarget || ! $this->needsGeneration($request, $definition, $locale, $current)) {
            return $locked ? null : $this->verifyExisting($request, $definition, $locale, $current, $previous, $map, $changes);
        }

        $seed = new SlugSeed($this->generator->body($model, $definition, $locale), true);

        if ($seed->body === '') {
            if ($current !== null) {
                // An emptied source never wipes an existing slug.
                return $this->verifyExisting($request, $definition, $locale, $current, $previous, $map, $changes);
            }

            $seed = match ($definition->emptySource) {
                EmptySourcePolicy::Skip => null,
                EmptySourcePolicy::Fail => throw SlugGenerationException::emptySource($model, $definition->column, $locale),
                EmptySourcePolicy::Random => new SlugSeed(Slugger::random($definition->randomLength, $definition->format->lowercase), true),
            };

            if ($seed === null) {
                return null;
            }
        }

        if ($current !== null && $request->mode !== RegenerationMode::All) {
            $base = $this->generator->compose($model, $definition, $locale, $seed);

            if ($current === $base || $this->keepsSuffixed($request, $definition, $locale, $current, $base, $map)) {
                return $this->verifyExisting($request, $definition, $locale, $current, $previous, $map, $changes);
            }
        }

        $value = $this->generator->unique($this->generator->context($model, $definition, $locale, $map), $definition, $locale, $seed);
        $reason = $current === null ? ChangeReason::Generated : ChangeReason::Regenerated;

        return $this->record($model, $definition, $locale, $previous, $value, $reason, $seed, $changes);
    }

    /**
     * No churn: `chair-2` stays `chair-2` while its base is still `chair`. Shape alone cannot tell
     * a collision suffix from part of the old source (`room-101` once `Room 101` became `Room`),
     * so the base must be the one the persisted sources produce, and an explicit recompute also
     * wants the bare base genuinely taken — otherwise the suffix is stale.
     *
     * @param  array<string, string>  $map
     */
    private function keepsSuffixed(SlugGenerationRequest $request, ResolvedSlugDefinition $definition, ?string $locale, string $current, string $base, array $map): bool
    {
        $model = $request->model;

        if (preg_match($definition->suffixPattern($base), $current) !== 1) {
            return false;
        }

        if ($model->exists && $this->generator->originalBase($model, $definition, $locale) !== $base) {
            return false;
        }

        if (! in_array($request->trigger, [GenerationTrigger::Manual, GenerationTrigger::Command], true)) {
            return true;
        }

        return $this->checker->isTaken($this->generator->context($model, $definition, $locale, $map), $definition, $locale, $base);
    }

    private function needsGeneration(SlugGenerationRequest $request, ResolvedSlugDefinition $definition, ?string $locale, ?string $current): bool
    {
        return match ($request->trigger) {
            GenerationTrigger::Creating => $definition->onCreate && $current === null,
            GenerationTrigger::Restoring => false,
            GenerationTrigger::Updating => match ($definition->onUpdate) {
                UpdatePolicy::Never => false,
                UpdatePolicy::IfEmpty => $current === null,
                UpdatePolicy::WhenSourceChanges => $current === null || $this->sources->changed($request->model, $definition, $locale),
                UpdatePolicy::Always => true,
            },
            GenerationTrigger::Manual, GenerationTrigger::Command => $request->mode === RegenerationMode::Missing
                ? $current === null
                : true,
        };
    }

    /**
     * Re-check an existing value whose uniqueness may have changed under it: its scope column
     * changed on update, or its row is being restored while trashed rows do not count as taken.
     *
     * @param  array<string, string>  $map
     * @param  list<SlugChange>  $changes
     */
    private function verifyExisting(
        SlugGenerationRequest $request,
        ResolvedSlugDefinition $definition,
        ?string $locale,
        ?string $current,
        ?string $previous,
        array $map,
        array &$changes,
    ): ?string {
        $model = $request->model;

        if ($current === null || ! $definition->isUnique() || ! $model->exists) {
            return null;
        }

        $restoring = $request->trigger === GenerationTrigger::Restoring && ! $definition->includeTrashed;
        $rescoped = $request->trigger === GenerationTrigger::Updating && $this->scopeChanged($model, $definition);

        if (! $restoring && ! $rescoped) {
            return null;
        }

        $context = $this->generator->context($model, $definition, $locale, $map);

        if (! $this->checker->isTaken($context, $definition, $locale, $current)) {
            return null;
        }

        if ($definition->manual === ManualSlugPolicy::Strict) {
            throw SlugAlreadyTakenException::forManual($model, $definition->column, $locale, $current);
        }

        $key = $locale ?? Sluggable::SINGLE_KEY;
        $seed = $this->state->seed($model, $definition->column, $key) ?? $this->unrecordedSeed($model, $definition, $locale, $current);
        $value = $this->generator->unique($context, $definition, $locale, $seed);

        return $this->record(
            $model,
            $definition,
            $locale,
            $previous,
            $value,
            $restoring ? ChangeReason::Restored : ChangeReason::Regenerated,
            $seed,
            $changes,
        );
    }

    /**
     * The seed of a value no save in this request wrote (a re-scope or a restore of a freshly
     * loaded model): the source body when the value is that body's base or a suffixed form of it,
     * so `chair-2` re-suffixes from `chair` instead of becoming `chair-2-2`; else the value itself.
     */
    private function unrecordedSeed(Model $model, ResolvedSlugDefinition $definition, ?string $locale, string $current): SlugSeed
    {
        $body = $this->generator->body($model, $definition, $locale);

        if ($body !== '') {
            $seed = new SlugSeed($body, true);

            if (preg_match($definition->suffixPattern($this->generator->compose($model, $definition, $locale, $seed)), $current) === 1) {
                return $seed;
            }
        }

        return new SlugSeed($current, false);
    }

    /**
     * How a host-supplied value becomes a candidate; null when it normalises to nothing.
     */
    private function manualSeed(ProbeContext $context, ResolvedSlugDefinition $definition, ?string $locale, string $value): ?SlugSeed
    {
        if ($definition->manual === ManualSlugPolicy::Normalize) {
            $body = Slugger::slugify($value, $definition->format, $definition->languageFor($locale), $locale);

            return $body === '' ? null : new SlugSeed($body, false);
        }

        if (! Slugger::isSafeSegment($value)) {
            throw SlugGenerationException::unsafeValue($context->model, $definition->column, $locale);
        }

        if ($definition->manual === ManualSlugPolicy::Strict) {
            // Strict stores the value as given, so nothing else bounds it: an over-length slug
            // would never bind, and overflows the column on MySQL/PostgreSQL.
            if (mb_strlen($value) > $definition->format->maxLength) {
                throw SlugGenerationException::tooLong($context->model, $definition->column, $locale, mb_strlen($value), $definition->format->maxLength);
            }

            if ($this->checker->isTaken($context, $definition, $locale, $value)) {
                throw SlugAlreadyTakenException::forManual($context->model, $definition->column, $locale, $value);
            }
        }

        return new SlugSeed($value, false);
    }

    private function scopeChanged(Model $model, ResolvedSlugDefinition $definition): bool
    {
        foreach ($definition->scopeColumns as $column) {
            if ($model->isDirty($column)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<SlugChange> $changes */
    private function record(
        Model $model,
        ResolvedSlugDefinition $definition,
        ?string $locale,
        ?string $previous,
        string $value,
        ChangeReason $reason,
        SlugSeed $seed,
        array &$changes,
    ): string {
        $this->state->remember($model, $definition->column, $locale ?? Sluggable::SINGLE_KEY, $value, $reason, $seed);

        if ($value !== $previous) {
            $changes[] = new SlugChange($definition->column, $locale, $previous, $value, $reason);
        }

        return $value;
    }

    private static function stringValue(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
