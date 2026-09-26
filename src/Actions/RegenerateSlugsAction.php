<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Sluggable\DataTransferObjects\RegeneratedSlug;
use RoundlyConsulting\Sluggable\DataTransferObjects\RegenerateSlugsData;
use RoundlyConsulting\Sluggable\DataTransferObjects\RegenerationReport;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugGenerationRequest;
use RoundlyConsulting\Sluggable\Enums\GenerationTrigger;
use RoundlyConsulting\Sluggable\Events\SlugsRegenerated;
use RoundlyConsulting\Sluggable\SlugManager;
use RoundlyConsulting\Sluggable\Support\SlugState;

/**
 * Backfill or regenerate slugs row by row (chunked by key, every row incl. trashed and rows a
 * global scope hides), saving each changed row normally so history and events behave as for any
 * save. Locks are respected unless forced.
 */
final readonly class RegenerateSlugsAction
{
    /** Changes kept in the report for display. */
    private const SAMPLES = 20;

    public function __construct(
        private GenerateSlugsAction $generate,
        private SlugState $state,
        private SlugManager $manager,
        private Dispatcher $events,
    ) {}

    public function execute(RegenerateSlugsData $data): RegenerationReport
    {
        $prototype = new ($data->modelClass);
        $query = $prototype->newQueryWithoutScopes();

        if ($data->keys !== null) {
            $query->whereKey($data->keys);
        }

        $scanned = 0;
        $changed = 0;
        $samples = [];

        foreach ($query->lazyById($data->chunk, $prototype->getQualifiedKeyName(), $prototype->getKeyName()) as $model) {
            $scanned++;

            $result = $this->generate->execute(new SlugGenerationRequest(
                model: $model,
                trigger: GenerationTrigger::Command,
                columns: $data->columns === [] ? null : $data->columns,
                locales: $data->locales === [] ? null : $data->locales,
                force: $data->force,
                mode: $data->mode,
            ));

            if ($result->isEmpty()) {
                $this->state->finish($model);

                continue;
            }

            $changed++;

            foreach ($result->changes as $change) {
                if (count($samples) < self::SAMPLES) {
                    $samples[] = new RegeneratedSlug($this->key($model), $change);
                }
            }

            if ($data->dryRun) {
                $this->state->finish($model);

                continue;
            }

            $entry = $this->state->entry($model);
            $entry->forceHistory = $data->withHistory;
            $entry->silent = $data->withoutEvents;

            $data->force
                ? $this->manager->unlocked(static fn (): bool => $model->save())
                : $model->save();
        }

        $report = new RegenerationReport($scanned, $changed, $samples);

        if (! $data->dryRun) {
            $this->events->dispatch(new SlugsRegenerated($data->modelClass, $report));
        }

        return $report;
    }

    private function key(Model $model): int|string
    {
        $key = $model->getKey();

        return is_int($key) ? $key : (string) (is_scalar($key) ? $key : '');
    }
}
