<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Schema\Drivers;

use Closure;
use Illuminate\Database\Connection;
use RoundlyConsulting\Sluggable\DataTransferObjects\PlannedIndex;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugIndexSpec;
use RoundlyConsulting\Sluggable\Enums\SlugStorage;
use RoundlyConsulting\Sluggable\Support\IndexNames;

/**
 * The shared shape of the Postgres and SQLite DDL: `CREATE UNIQUE INDEX IF NOT EXISTS` over the
 * scope columns plus the slug (or its per-locale JSON expression), partial when trashed rows are
 * exempt.
 */
final class ExpressionIndexes
{
    /**
     * @param  list<string>  $locales
     * @param  Closure(string): string  $expression  wraps the grammar's JSON path as an index element
     * @return list<PlannedIndex>
     */
    public static function plan(SlugIndexSpec $spec, Connection $connection, array $locales, Closure $expression): array
    {
        $grammar = $connection->getQueryGrammar();
        $table = $grammar->wrapTable($spec->table);
        $scope = array_map(static fn (string $column): string => $grammar->wrap($column), $spec->scope);
        $where = $spec->includeTrashed ? '' : ' where '.$grammar->wrap($spec->deletedAtColumn).' is null';

        if ($spec->storage === SlugStorage::String) {
            $name = IndexNames::index($spec->table, $spec->column);
            $columns = implode(', ', [...$scope, $grammar->wrap($spec->column)]);

            return [new PlannedIndex($spec->table, $name, [
                "create unique index if not exists {$grammar->wrap($name)} on {$table} ({$columns}){$where}",
            ])];
        }

        $planned = [];

        foreach ($locales as $locale) {
            $name = IndexNames::index($spec->table, $spec->column, $locale);
            $columns = implode(', ', [...$scope, $expression($grammar->wrap($spec->column.'->'.$locale))]);

            $planned[] = new PlannedIndex($spec->table, $name, [
                "create unique index if not exists {$grammar->wrap($name)} on {$table} ({$columns}){$where}",
            ]);
        }

        return $planned;
    }
}
