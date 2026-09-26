<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugGenerationResult;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\Support\SluggableConfig;
use RoundlyConsulting\Sluggable\Support\SlugHistoryModel;
use RoundlyConsulting\Sluggable\Support\SlugState;

/**
 * Retire the previous value of every persisted slug change into the history table (restoring a
 * previously pruned-by-reclaim row), and reclaim: a model taking back one of its own retired slugs
 * removes that entry, so it never redirects to itself.
 */
final readonly class RecordSlugHistoryAction
{
    public function __construct(
        private SlugState $state,
    ) {}

    public function execute(SlugGenerationResult $result, Model $model): void
    {
        $options = $this->state->options($model);
        $force = $this->state->entry($model)->forceHistory;

        $changes = array_values(array_filter(
            $result->changes,
            static fn ($change): bool => $force || ($options->find($change->column)->keepHistory ?? false),
        ));

        if ($changes === [] || ! $this->tableReady()) {
            return;
        }

        foreach ($changes as $change) {
            $entries = SlugHistoryModel::query()
                ->withTrashed()
                ->where('sluggable_type', $model->getMorphClass())
                ->where('sluggable_id', $model->getKey())
                ->where('column', $change->column)
                ->where('locale', $change->locale ?? '');

            // Reclaim: the model's new value must not stay listed as one of its retired slugs.
            (clone $entries)->where('slug', $change->current)->forceDelete();

            if ($change->previous === null || $change->previous === $change->current) {
                continue;
            }

            $entry = (clone $entries)->where('slug', $change->previous)->first();

            if ($entry !== null) {
                $entry->forceFill(['deleted_at' => null, 'created_at' => now()])->save();

                continue;
            }

            SlugHistoryModel::new()->forceFill([
                'sluggable_type' => $model->getMorphClass(),
                'sluggable_id' => $model->getKey(),
                'column' => $change->column,
                'locale' => $change->locale ?? '',
                'slug' => $change->previous,
            ])->save();
        }
    }

    /**
     * History on and the table missing is loud where a developer will see it and a logged
     * warning in production — never a 500 after the model row was already written.
     */
    private function tableReady(): bool
    {
        if (SlugHistoryModel::tableExists()) {
            return true;
        }

        $table = SluggableConfig::historyTable();

        if (app()->environment(['local', 'testing'])) {
            throw InvalidSlugDefinitionException::historyTableMissing($table);
        }

        Log::warning("sluggable: slug history is enabled but the [{$table}] table does not exist; history was not recorded.");

        return false;
    }
}
