<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugSeed;
use RoundlyConsulting\Sluggable\Definitions\ResolvedSlugOptions;
use RoundlyConsulting\Sluggable\Enums\ChangeReason;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use WeakMap;

/**
 * Per-save state kept OFF the model: a WeakMap keyed by instance, so nothing leaks into
 * serialize()/queue payloads and every entry dies with its model (Octane-safe).
 */
final class SlugState
{
    /** @var WeakMap<Model, SlugStateEntry> */
    private WeakMap $entries;

    public function __construct()
    {
        $this->entries = new WeakMap;
    }

    public function entry(Model $model): SlugStateEntry
    {
        return $this->entries[$model] ??= new SlugStateEntry;
    }

    /** The model's resolved definitions, cached on the instance until the next save starts. */
    public function options(Model $model): ResolvedSlugOptions
    {
        $entry = $this->entry($model);

        if ($entry->options === null) {
            if (! $model instanceof Sluggable) {
                throw InvalidSlugDefinitionException::missingContract($model::class);
            }

            SlugOptionsRegistry::assertNoConflict($model::class);

            $entry->options = $model->slugOptions()->resolve($model);
        }

        return $entry->options;
    }

    public function refreshOptions(Model $model): void
    {
        $this->entry($model)->options = null;
    }

    public function remember(Model $model, string $column, string $key, string $value, ChangeReason $reason, SlugSeed $seed): void
    {
        $entry = $this->entry($model);
        $entry->written[$column][$key] = $value;
        $entry->reasons[$column][$key] = $reason;
        $entry->seeds[$column][$key] = $seed;
    }

    public function written(Model $model, string $column, string $key): ?string
    {
        return $this->entry($model)->written[$column][$key] ?? null;
    }

    public function reason(Model $model, string $column, string $key): ?ChangeReason
    {
        return $this->entry($model)->reasons[$column][$key] ?? null;
    }

    public function seed(Model $model, string $column, string $key): ?SlugSeed
    {
        return $this->entry($model)->seeds[$column][$key] ?? null;
    }

    public function isRetrying(Model $model): bool
    {
        return $this->entry($model)->retrying;
    }

    /** Forget everything a finished save recorded (options stay cached). */
    public function finish(Model $model): void
    {
        $entry = $this->entry($model);
        $entry->written = [];
        $entry->reasons = [];
        $entry->seeds = [];
        $entry->retrying = false;
        $entry->forceHistory = false;
        $entry->silent = false;
    }
}
