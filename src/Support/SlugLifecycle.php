<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Support;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Sluggable\Actions\GenerateSlugsAction;
use RoundlyConsulting\Sluggable\Actions\RecordSlugHistoryAction;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugChange;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugGenerationRequest;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugGenerationResult;
use RoundlyConsulting\Sluggable\Enums\ChangeReason;
use RoundlyConsulting\Sluggable\Enums\GenerationTrigger;
use RoundlyConsulting\Sluggable\Events\SlugChanged;
use RoundlyConsulting\Sluggable\SlugManager;

/**
 * The model-event side of HasSlug. The trait only registers hooks; everything they do lives here.
 */
final readonly class SlugLifecycle
{
    public function __construct(
        private SlugState $state,
        private SlugManager $manager,
        private Dispatcher $events,
    ) {}

    public function creating(Model $model): void
    {
        $this->generate($model, GenerationTrigger::Creating);
    }

    public function updating(Model $model): void
    {
        $this->generate($model, GenerationTrigger::Updating);
    }

    public function restoring(Model $model): void
    {
        $this->generate($model, GenerationTrigger::Restoring);
    }

    /** Record history and announce every persisted change, then forget the save's state. */
    public function saved(Model $model): void
    {
        try {
            if (! $this->manager->isGenerationEnabled()) {
                return;
            }

            $result = $this->persistedChanges($model);

            if ($result->isEmpty()) {
                return;
            }

            app(RecordSlugHistoryAction::class)->execute($result, $model);

            if ($this->state->entry($model)->silent) {
                return;
            }

            foreach ($result->changes as $change) {
                $this->events->dispatch(new SlugChanged(
                    $model,
                    $change->column,
                    $change->locale,
                    $change->previous,
                    $change->current,
                    $change->reason,
                ));
            }
        } finally {
            $this->state->finish($model);
        }
    }

    /**
     * Purge the model's history when its row is really gone: a model without soft deletes, or a
     * force delete. A soft delete keeps history (the row can come back).
     */
    public function deleted(Model $model): void
    {
        $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($model), true);

        if ($softDeletes && ! (method_exists($model, 'isForceDeleting') && $model->isForceDeleting())) {
            return;
        }

        $keepsHistory = false;

        foreach ($this->state->options($model)->definitions as $definition) {
            $keepsHistory = $keepsHistory || $definition->keepHistory;
        }

        if (! $keepsHistory || ! SlugHistoryModel::tableExists()) {
            return;
        }

        SlugHistoryModel::query()
            ->withTrashed()
            ->where('sluggable_type', $model->getMorphClass())
            ->where('sluggable_id', $model->getKey())
            ->forceDelete();
    }

    private function generate(Model $model, GenerationTrigger $trigger): void
    {
        if (! $this->manager->isGenerationEnabled() || $this->state->isRetrying($model)) {
            return;
        }

        // Definitions may read config or model state; resolve them afresh for every save.
        $this->state->refreshOptions($model);

        app(GenerateSlugsAction::class)->execute(new SlugGenerationRequest($model, $trigger));
    }

    /**
     * What this save actually persisted, diffed from the raw original (still pre-save while
     * `saved` fires) — manual changes included.
     */
    private function persistedChanges(Model $model): SlugGenerationResult
    {
        $changes = [];

        foreach ($this->state->options($model)->definitions as $definition) {
            $column = $definition->column;

            // Generation skipped it, so this save did not write it either.
            if ($definition->isUnloadedOn($model)) {
                continue;
            }

            if ($definition->isLocalized()) {
                $previous = LocaleMapAccessor::original($model, $column);

                foreach (LocaleMapAccessor::read($model, $column) as $locale => $value) {
                    if (($previous[$locale] ?? null) !== $value) {
                        $changes[] = $this->change($model, $column, $locale, $previous[$locale] ?? null, $value);
                    }
                }

                continue;
            }

            $value = $model->getAttribute($column);
            $previous = $model->getRawOriginal($column);

            if (is_string($value) && $value !== '' && $value !== $previous) {
                $changes[] = $this->change($model, $column, null, is_string($previous) && $previous !== '' ? $previous : null, $value);
            }
        }

        return new SlugGenerationResult($changes);
    }

    private function change(Model $model, string $column, ?string $locale, ?string $previous, string $current): SlugChange
    {
        $reason = $this->state->reason($model, $column, $locale ?? Sluggable::SINGLE_KEY) ?? ChangeReason::Manual;

        return new SlugChange($column, $locale, $previous, $current, $reason);
    }
}
