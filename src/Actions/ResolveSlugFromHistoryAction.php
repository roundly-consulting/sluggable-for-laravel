<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Sluggable\DataTransferObjects\HistoryLookup;
use RoundlyConsulting\Sluggable\Support\SlugHistoryModel;

/**
 * The most recent model that retired a slug AND is visible through the caller's query. A draft,
 * another tenant's row or a trashed row (on a route without withTrashed()) is never returned.
 */
final class ResolveSlugFromHistoryAction
{
    /** Candidate owners considered per lookup — the lookup stays one indexed query + one fetch. */
    private const CANDIDATES = 50;

    public function execute(HistoryLookup $lookup): ?Model
    {
        if (! SlugHistoryModel::tableExists()) {
            return null;
        }

        $model = new ($lookup->modelClass);

        $query = SlugHistoryModel::query()
            ->where('sluggable_type', $model->getMorphClass())
            ->where('column', $lookup->column)
            ->where('slug', $lookup->slug);

        if ($lookup->locale !== null) {
            $query->where('locale', $lookup->locale);
        }

        $keys = $query->orderByDesc('id')->limit(self::CANDIDATES)->pluck('sluggable_id')->all();

        if ($keys === []) {
            return null;
        }

        $visible = $lookup->scopeQuery->whereKey($keys)->get();

        foreach ($keys as $key) {
            foreach ($visible as $candidate) {
                if ((string) $candidate->getKey() === (string) $key) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
