<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Sluggable\Contracts\SlugLocales;
use RoundlyConsulting\Sluggable\DataTransferObjects\PlannedIndex;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugIndexReport;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugIndexSpec;
use RoundlyConsulting\Sluggable\Enums\LocaleUniqueness;
use RoundlyConsulting\Sluggable\Enums\SlugStorage;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\Exceptions\UnsupportedDriverException;
use RoundlyConsulting\Sluggable\Schema\Drivers\MysqlSlugIndexes;
use RoundlyConsulting\Sluggable\Schema\Drivers\PostgresSlugIndexes;
use RoundlyConsulting\Sluggable\Schema\Drivers\SlugIndexDriver;
use RoundlyConsulting\Sluggable\Schema\Drivers\SqliteSlugIndexes;
use RoundlyConsulting\Sluggable\SlugManager;
use RoundlyConsulting\Sluggable\Support\IdentifierGuard;
use RoundlyConsulting\Sluggable\Support\IndexNames;

/**
 * Engine-native unique indexes for slug columns — per locale for locale maps, partial (or a
 * generated column on MySQL) when trashed rows are exempt. Idempotent: an index that already
 * exists is skipped, so migrations and `sluggable:indexes` can run it again after adding a locale.
 *
 * ```php
 * SlugIndexes::ensure(SlugIndexSpec::localeMap('topics', 'slug', scope: ['shop_id']));
 * SlugIndexes::forModel(Topic::class);
 * ```
 */
final class SlugIndexes
{
    public static function ensure(SlugIndexSpec $spec, bool $dryRun = false): SlugIndexReport
    {
        $connection = self::connection($spec);
        $schema = $connection->getSchemaBuilder();
        $report = new SlugIndexReport;

        foreach (self::planned($spec, $connection) as $index) {
            if ($schema->hasIndex($index->table, $index->name)) {
                $report = $report->merge(new SlugIndexReport(skipped: [$index->name]));

                continue;
            }

            $statements = $index->generatedColumn !== null && $schema->hasColumn($index->table, $index->generatedColumn)
                ? $index->statements
                : $index->ddl();

            if (! $dryRun) {
                foreach ($statements as $statement) {
                    $connection->statement($statement);
                }
            }

            $report = $report->merge(new SlugIndexReport(created: [$index->name], statements: $statements));
        }

        return $report;
    }

    /**
     * The DDL a spec needs, without executing anything.
     *
     * @return list<string>
     */
    public static function plan(SlugIndexSpec $spec): array
    {
        $statements = [];

        foreach (self::planned($spec, self::connection($spec)) as $index) {
            $statements = [...$statements, ...$index->ddl()];
        }

        return $statements;
    }

    /**
     * Ensure the indexes every DB-enforceable definition of a model needs.
     *
     * @param  class-string<Model>  $model
     * @param  list<string>|null  $columns
     */
    public static function forModel(string $model, ?array $columns = null, bool $dryRun = false): SlugIndexReport
    {
        $report = new SlugIndexReport;

        foreach (self::specsFor($model, $columns) as $spec) {
            $report = $report->merge(self::ensure($spec, $dryRun));
        }

        return $report;
    }

    /**
     * Specs derived from a model's definitions. Closure scopes and across-locales uniqueness have
     * no index form and are skipped (app-level only).
     *
     * @param  class-string<Model>  $model
     * @param  list<string>|null  $columns
     * @return list<SlugIndexSpec>
     */
    public static function specsFor(string $model, ?array $columns = null): array
    {
        $instance = new $model;
        $options = app(SlugManager::class)->options($instance);
        $specs = [];

        foreach ($options->definitions as $definition) {
            if ($columns !== null && ! in_array($definition->column, $columns, true)) {
                continue;
            }

            if (! $definition->isUnique()
                || $definition->scopeClosure !== null
                || $definition->localeUniqueness === LocaleUniqueness::AcrossLocales && $definition->isLocalized()) {
                continue;
            }

            $specs[] = new SlugIndexSpec(
                table: $instance->getTable(),
                column: $definition->column,
                storage: $definition->storage,
                scope: $definition->scopeColumns,
                includeTrashed: $definition->includeTrashed,
                connection: $instance->getConnectionName(),
                deletedAtColumn: method_exists($instance, 'getDeletedAtColumn') ? (string) $instance->getDeletedAtColumn() : 'deleted_at',
            );
        }

        return $specs;
    }

    /** @return list<PlannedIndex> */
    private static function planned(SlugIndexSpec $spec, Connection $connection): array
    {
        $driver = self::driver($connection);

        if (! $spec->includeTrashed && ! $connection->getSchemaBuilder()->hasColumn($spec->table, $spec->deletedAtColumn)) {
            throw InvalidSlugDefinitionException::invalidIdentifier($spec->table.'.'.$spec->deletedAtColumn, 'deleted-at column');
        }

        $locales = [];

        if ($spec->storage === SlugStorage::LocaleMap) {
            // Explicit spec locales are validated by SlugIndexSpec; the SlugLocales defaults must
            // pass the same allowlist before they are spliced into DDL.
            $locales = array_map(IdentifierGuard::locale(...), $spec->locales ?? app(SlugLocales::class)->supported());
            IndexNames::assertDistinct($locales);
        }

        return $driver->plan($spec, $connection, $locales);
    }

    private static function driver(Connection $connection): SlugIndexDriver
    {
        return match ($connection->getDriverName()) {
            'pgsql' => new PostgresSlugIndexes,
            'mysql', 'mariadb' => new MysqlSlugIndexes,
            'sqlite' => new SqliteSlugIndexes,
            default => throw UnsupportedDriverException::forIndexes($connection->getDriverName()),
        };
    }

    private static function connection(SlugIndexSpec $spec): Connection
    {
        return app('db')->connection($spec->connection);
    }
}
