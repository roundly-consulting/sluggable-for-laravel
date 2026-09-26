<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Sluggable\Database\Factories\SlugHistoryFactory;
use RoundlyConsulting\Sluggable\Support\SluggableConfig;

/**
 * A retired slug: the value a model's column (and locale) used to have, kept so old URLs can be
 * redirected to the current one. Swappable via `sluggable.history.model` — deliberately not final.
 *
 * @property int $id
 * @property string $sluggable_type
 * @property int|string $sluggable_id
 * @property string $column
 * @property string $locale
 * @property string $slug
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class SlugHistory extends Model
{
    /** @use HasFactory<SlugHistoryFactory> */
    use HasFactory;

    use MassPrunable;
    use SoftDeletes;

    protected $guarded = [];

    public function getTable(): string
    {
        return SluggableConfig::historyTable();
    }

    /** @return MorphTo<Model, $this> */
    public function sluggable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Entries older than `sluggable.history.prune_after_days`; nothing when that is null (keep
     * forever).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = SluggableConfig::pruneAfterDays();

        if ($days === null) {
            return $this->newQuery()->whereRaw('1 = 0');
        }

        return $this->newQuery()->where($this->qualifyColumn('created_at'), '<', now()->subDays($days));
    }

    protected static function newFactory(): SlugHistoryFactory
    {
        return SlugHistoryFactory::new();
    }
}
