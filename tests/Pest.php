<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Definitions\SlugOptions;
use RoundlyConsulting\Sluggable\Tests\Fixtures\Configurable;
use RoundlyConsulting\Sluggable\Tests\TestCase;
use RoundlyConsulting\Testing\Database\DriverMatrix;

uses(TestCase::class)->in(__DIR__);

/**
 * Give a Configurable fixture model the slug definitions one test needs.
 *
 * @param  class-string  $class
 */
function definitionFor(string $class, SlugDefinition ...$definitions): void
{
    Configurable::set($class, static fn (): SlugOptions => SlugOptions::make(...$definitions));
}

/** Whether the suite runs on the given real engine (the leg's own driver). */
function onDriver(string ...$drivers): bool
{
    return in_array(DriverMatrix::driver(), $drivers, true);
}

/**
 * Drop a slug unique index whichever way it was created: a `unique()` constraint (Blueprint) or a
 * raw `CREATE UNIQUE INDEX` (SlugIndexes on Postgres/SQLite).
 */
function dropSlugIndex(string $table, string $name): void
{
    $schema = Schema::getConnection()->getSchemaBuilder();

    try {
        $schema->table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($name));
    } catch (QueryException) {
        $schema->table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
    }
}
