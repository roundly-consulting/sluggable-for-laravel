<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RoundlyConsulting\Sluggable\Actions\FindDuplicateSlugsAction;
use RoundlyConsulting\Sluggable\Actions\QueueSlugRegenerationAction;
use RoundlyConsulting\Sluggable\Actions\RegenerateSlugsAction;
use RoundlyConsulting\Sluggable\Actions\ResolveSlugFromHistoryAction;
use RoundlyConsulting\Sluggable\DataTransferObjects\DuplicateScan;
use RoundlyConsulting\Sluggable\DataTransferObjects\HistoryLookup;
use RoundlyConsulting\Sluggable\DataTransferObjects\RegenerateSlugsData;
use RoundlyConsulting\Sluggable\DataTransferObjects\RegenerationReport;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugDuplicate;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugIndexReport;
use RoundlyConsulting\Sluggable\Definitions\ResolvedSlugOptions;
use RoundlyConsulting\Sluggable\Enums\RegenerationMode;
use RoundlyConsulting\Sluggable\Schema\SlugIndexes;
use RoundlyConsulting\Sluggable\Support\IdentifierGuard;
use RoundlyConsulting\Sluggable\Support\SlugState;

/**
 * Class-wide slug operations for one sluggable model — `Slugs::model(Post::class)`. Every method
 * resolves its action through the container, so host overrides apply.
 */
final readonly class ModelSlugs
{
    /** @param class-string<Model> $modelClass */
    public function __construct(
        private Container $container,
        public string $modelClass,
    ) {}

    /**
     * Backfill or regenerate every row now (chunked by key, incl. trashed and rows a global scope
     * hides), saving each changed row normally so history and events behave as for any save.
     * Locks are respected unless `force`; a dry run writes nothing and reports what would change.
     *
     * @param  list<string>  $columns  [] = every slug column
     * @param  list<string>  $locales  [] = each definition's target locales
     */
    public function regenerate(
        RegenerationMode $mode = RegenerationMode::Missing,
        bool $dryRun = false,
        bool $withHistory = false,
        array $columns = [],
        array $locales = [],
        int $chunk = 500,
        bool $withoutEvents = false,
        bool $force = false,
    ): RegenerationReport {
        return $this->container->make(RegenerateSlugsAction::class)->execute(
            $this->regeneration($mode, $dryRun, $withHistory, $columns, $locales, $chunk, $withoutEvents, $force),
        );
    }

    /**
     * The same run on the queue: one `RegenerateSlugsJob` per chunk of primary keys. Returns the
     * number of jobs dispatched.
     *
     * @param  list<string>  $columns  [] = every slug column
     * @param  list<string>  $locales  [] = each definition's target locales
     */
    public function queueRegeneration(
        RegenerationMode $mode = RegenerationMode::Missing,
        bool $withHistory = false,
        array $columns = [],
        array $locales = [],
        int $chunk = 500,
        bool $withoutEvents = false,
        bool $force = false,
    ): int {
        return $this->container->make(QueueSlugRegenerationAction::class)->execute(
            $this->regeneration($mode, false, $withHistory, $columns, $locales, $chunk, $withoutEvents, $force),
        );
    }

    /**
     * Slugs a unique index would reject — values shared by several rows of one scope (and
     * locale) — plus values longer than the definition allows. Run it before adding an index.
     *
     * @return list<SlugDuplicate>
     */
    public function duplicates(?string $column = null, ?string $locale = null): array
    {
        return $this->container->make(FindDuplicateSlugsAction::class)->execute(
            new DuplicateScan($this->modelClass, $column, $locale),
        );
    }

    /**
     * Ensure the engine-native unique indexes every DB-enforceable definition needs (idempotent:
     * existing indexes are skipped). A dry run only reports the DDL.
     *
     * @param  list<string>|null  $columns  null = every slug column
     */
    public function indexes(bool $dryRun = false, ?array $columns = null): SlugIndexReport
    {
        return SlugIndexes::forModel($this->modelClass, $columns, $dryRun);
    }

    /**
     * The most recent row that retired a slug and is visible through `$within` (default: the
     * model's own query, global scopes applied) — a draft, another tenant's row or a trashed row
     * is never returned unless that query would return it.
     *
     * @param  string|null  $locale  null = any locale (locale-map columns)
     * @param  Builder<*>|Relation<*, *, *>|null  $within
     */
    public function findInHistory(string $slug, ?string $column = null, ?string $locale = null, Builder|Relation|null $within = null): ?Model
    {
        $prototype = new ($this->modelClass);
        $definition = $this->container->make(SlugState::class)->options($prototype)->get($column);

        return $this->container->make(ResolveSlugFromHistoryAction::class)->execute(new HistoryLookup(
            modelClass: $this->modelClass,
            slug: $slug,
            column: $definition->column,
            locale: $definition->isLocalized() ? ($locale === null ? null : IdentifierGuard::locale($locale)) : '',
            scopeQuery: $within ?? $prototype->newQuery(),
        ));
    }

    /** The model's resolved slug definitions. */
    public function options(): ResolvedSlugOptions
    {
        return $this->container->make(SlugState::class)->options(new ($this->modelClass));
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $locales
     */
    private function regeneration(
        RegenerationMode $mode,
        bool $dryRun,
        bool $withHistory,
        array $columns,
        array $locales,
        int $chunk,
        bool $withoutEvents,
        bool $force,
    ): RegenerateSlugsData {
        return new RegenerateSlugsData(
            modelClass: $this->modelClass,
            columns: $columns,
            locales: array_map(IdentifierGuard::locale(...), $locales),
            mode: $mode,
            chunk: $chunk,
            dryRun: $dryRun,
            withHistory: $withHistory,
            withoutEvents: $withoutEvents,
            force: $force,
        );
    }
}
