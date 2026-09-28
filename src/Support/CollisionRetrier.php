<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Support;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\Contracts\SlugLocales;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugSeed;
use RoundlyConsulting\Sluggable\Definitions\ResolvedSlugDefinition;
use RoundlyConsulting\Sluggable\Definitions\ResolvedSlugOptions;
use RoundlyConsulting\Sluggable\Enums\ChangeReason;
use RoundlyConsulting\Sluggable\Enums\ManualSlugPolicy;
use RoundlyConsulting\Sluggable\Events\SlugCollisionRetried;
use RoundlyConsulting\Sluggable\Exceptions\SlugAlreadyTakenException;
use RoundlyConsulting\Sluggable\Exceptions\SlugGenerationException;
use RoundlyConsulting\Sluggable\SlugManager;

/**
 * Closes the check-then-insert race: when the engine's unique index rejects a slug another writer
 * took in the meantime, re-probe, re-suffix and retry — bounded by the definition's `retries`.
 *
 * Inside an outer transaction each attempt runs in a SAVEPOINT (`transaction()` nests as one),
 * because a failed statement aborts a whole Postgres transaction and the retry would otherwise
 * fail with "current transaction is aborted".
 *
 * Only sluggable's own values are ever re-suffixed: with generation off the host writes its own
 * slugs and the database's violation surfaces untouched, and a `Strict` value no generation pass
 * produced in this save (a manual or `saveQuietly()` write) throws instead of being rewritten.
 */
final readonly class CollisionRetrier
{
    public function __construct(
        private SlugState $state,
        private UniqueViolationMatcher $matcher,
        private SlugGenerator $generator,
        private UniquenessChecker $checker,
        private SlugLocales $locales,
        private Dispatcher $events,
        private SlugManager $manager,
    ) {}

    /** @param Closure(): bool $write */
    public function run(Model $model, Closure $write): bool
    {
        if (! $this->manager->isGenerationEnabled()) {
            return $write();
        }

        $options = $this->state->options($model);
        $connection = $model->getConnection();
        $attempt = 0;

        try {
            while (true) {
                try {
                    return $connection->transactionLevel() > 0
                        ? (bool) $connection->transaction($write)
                        : $write();
                } catch (UniqueConstraintViolationException $exception) {
                    $definition = $this->matcher->match($exception, $model, $options, $this->localesOf($model, $options));

                    if ($definition === null || $definition->retries === 0) {
                        throw $exception;
                    }

                    if (++$attempt > $definition->retries) {
                        throw SlugGenerationException::retriesExhausted($model, $definition->column, $exception);
                    }

                    if (! $this->bump($model, $options)) {
                        throw SlugGenerationException::constraintMismatch($model, $definition->column, null, $exception);
                    }

                    $this->events->dispatch(new SlugCollisionRetried($model::class, $definition->column, $attempt));

                    // The retry re-fires creating/updating; sluggable's own hook must not regenerate.
                    $this->state->entry($model)->retrying = true;
                }
            }
        } finally {
            $this->state->entry($model)->retrying = false;
        }
    }

    /** Re-suffix every slug value that is now taken; false when none is (a definition/index mismatch). */
    private function bump(Model $model, ResolvedSlugOptions $options): bool
    {
        $bumped = false;

        foreach ($options->definitions as $definition) {
            if (! $definition->isUnique()) {
                continue;
            }

            if ($definition->isLocalized()) {
                $map = LocaleMapAccessor::read($model, $definition->column);
                $updated = $map;

                foreach ($map as $locale => $value) {
                    if ($this->checker->isTaken($this->generator->context($model, $definition, $locale, $updated)->locking(), $definition, $locale, $value)) {
                        $updated[$locale] = $this->resuffix($model, $definition, $locale, $value, $updated);
                        $bumped = true;
                    }
                }

                if ($updated !== $map) {
                    LocaleMapAccessor::write($model, $definition->column, $updated);
                }

                continue;
            }

            $value = $model->getAttribute($definition->column);

            if (is_string($value) && $value !== '' && $this->checker->isTaken($this->generator->context($model, $definition)->locking(), $definition, null, $value)) {
                $model->setAttribute($definition->column, $this->resuffix($model, $definition, null, $value, []));
                $bumped = true;
            }
        }

        return $bumped;
    }

    /** @param array<string, string> $map */
    private function resuffix(Model $model, ResolvedSlugDefinition $definition, ?string $locale, string $value, array $map): string
    {
        $key = $locale ?? Sluggable::SINGLE_KEY;
        $reason = $this->state->reason($model, $definition->column, $key);

        // Unrecorded = no generation pass wrote it in this save: the host's value, i.e. manual.
        if ($definition->manual === ManualSlugPolicy::Strict && ($reason === null || $reason === ChangeReason::Manual)) {
            throw SlugAlreadyTakenException::forManual($model, $definition->column, $locale, $value);
        }

        $seed = $this->state->seed($model, $definition->column, $key) ?? new SlugSeed($value, false);
        $fresh = $this->generator->unique($this->generator->context($model, $definition, $locale, $map)->locking(), $definition, $locale, $seed);

        $this->state->remember($model, $definition->column, $key, $fresh, ChangeReason::CollisionRetry, $seed);

        return $fresh;
    }

    /** @return list<string> */
    private function localesOf(Model $model, ResolvedSlugOptions $options): array
    {
        $locales = $this->locales->supported();

        foreach ($options->definitions as $definition) {
            if ($definition->isLocalized()) {
                $locales = [...$locales, ...array_keys(LocaleMapAccessor::read($model, $definition->column))];
            }
        }

        return array_values(array_unique(array_filter($locales, IdentifierGuard::isLocale(...))));
    }
}
