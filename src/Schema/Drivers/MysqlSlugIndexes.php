<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Schema\Drivers;

use Illuminate\Database\Connection;
use RoundlyConsulting\Sluggable\DataTransferObjects\PlannedIndex;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugIndexSpec;
use RoundlyConsulting\Sluggable\Enums\SlugStorage;
use RoundlyConsulting\Sluggable\Support\IndexNames;

/**
 * MySQL / MariaDB: no partial or JSON expression indexes, so each exemption becomes an INVISIBLE
 * virtual generated column that is NULL when the row is exempt (missing locale, JSON null,
 * trashed) — and NULLs never collide in a unique index.
 *
 * Locale columns use `utf8mb4_bin` because `JSON_UNQUOTE()` (what the app-level probe compares
 * against) is binary: a case-insensitive generated column would reject `Café` next to `cafe` after
 * the probe found it free. The `__live` column copies the source column's collation, so a string
 * column keeps comparing exactly as the host declared it.
 */
final class MysqlSlugIndexes implements SlugIndexDriver
{
    public function plan(SlugIndexSpec $spec, Connection $connection, array $locales): array
    {
        $grammar = $connection->getQueryGrammar();
        $table = $grammar->wrapTable($spec->table);
        $scope = array_map(static fn (string $column): string => $grammar->wrap($column), $spec->scope);
        $slug = $grammar->wrap($spec->column);
        $deletedAt = $grammar->wrap($spec->deletedAtColumn);

        if ($spec->storage === SlugStorage::String) {
            $name = IndexNames::index($spec->table, $spec->column);

            if ($spec->includeTrashed) {
                return [new PlannedIndex($spec->table, $name, [
                    "alter table {$table} add unique index {$grammar->wrap($name)} (".implode(', ', [...$scope, $slug]).')',
                ])];
            }

            $live = IndexNames::liveColumn($spec->column);
            $type = $this->sourceColumnType($connection, $spec);

            return [new PlannedIndex(
                $spec->table,
                $name,
                ["alter table {$table} add unique index {$grammar->wrap($name)} (".implode(', ', [...$scope, $grammar->wrap($live)]).')'],
                $live,
                "alter table {$table} add column {$grammar->wrap($live)} {$type} as (if({$deletedAt} is null, {$slug}, null)) virtual null invisible",
            )];
        }

        $pdo = $connection->getPdo();
        $planned = [];

        foreach ($locales as $locale) {
            $name = IndexNames::index($spec->table, $spec->column, $locale);
            $generated = IndexNames::localeColumn($spec->column, $locale);
            $path = $pdo->quote('$."'.$locale.'"');
            $trashed = $spec->includeTrashed ? '' : "{$deletedAt} is null and ";

            $planned[] = new PlannedIndex(
                $spec->table,
                $name,
                ["alter table {$table} add unique index {$grammar->wrap($name)} (".implode(', ', [...$scope, $grammar->wrap($generated)]).')'],
                $generated,
                "alter table {$table} add column {$grammar->wrap($generated)} varchar(255) collate utf8mb4_bin as "
                ."(case when {$trashed}json_type(json_extract({$slug}, {$path})) = 'STRING' "
                ."then json_unquote(json_extract({$slug}, {$path})) end) virtual null invisible",
            );
        }

        return $planned;
    }

    /** The slug column's own type and collation, so the generated copy compares identically. */
    private function sourceColumnType(Connection $connection, SlugIndexSpec $spec): string
    {
        foreach ($connection->getSchemaBuilder()->getColumns($spec->table) as $column) {
            if ($column['name'] !== $spec->column) {
                continue;
            }

            $type = preg_match('/^varchar\(\d{1,5}\)$/i', $column['type']) === 1 ? strtolower($column['type']) : 'varchar(255)';
            $collation = $column['collation'] !== null && preg_match('/^[A-Za-z0-9_]+$/', $column['collation']) === 1
                ? ' collate '.$column['collation']
                : '';

            return $type.$collation;
        }

        return 'varchar(255)';
    }
}
