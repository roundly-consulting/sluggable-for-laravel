<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\Models\SlugHistory;
use RoundlyConsulting\Testing\Assertions\Migrations\MigrationRunner;

/*
 * toHaveRunnableMigrationOrder / toRejectBrokenOrderOnConnection are NOT adopted: the package
 * ships ONE migration with ZERO foreign-key edges. The first would be a vacuous pin, and the
 * second is a negative control that fails by construction when every order is accepted.
 */

$migrations = __DIR__.'/../../../database/migrations';

it('applies the history migration on postgres', function () use ($migrations): void {
    expect($migrations)->toApplyOnConnection('pgsql', 1);
})->skip(fn (): bool => ! MigrationRunner::connectionIsAvailable('pgsql'), 'no postgres reachable');

it('applies the history migration on mysql', function () use ($migrations): void {
    expect($migrations)->toApplyOnConnection('mysql', 1);
})->skip(fn (): bool => ! MigrationRunner::connectionIsAvailable('mysql'), 'no mysql reachable');

it('creates the history table with a uuid morph id when configured', function (): void {
    Schema::drop('slug_history');
    config(['sluggable.key_type' => 'uuid']);

    (require __DIR__.'/../../../database/migrations/create_slug_history_table.php')->up();

    $columns = collect(Schema::getColumns('slug_history'))->keyBy('name');

    expect($columns->has('sluggable_id'))->toBeTrue()
        ->and(strtolower((string) $columns['sluggable_id']['type_name']))->toBeIn(['uuid', 'char', 'varchar']);
});

it('keeps the history model swappable', function (): void {
    expect(SlugHistory::class)->toBeSwappableVia('sluggable.history.model');
});

it('creates the default history table when the configured name is blank, like the model', function (?string $blank): void {
    Schema::drop('slug_history');
    config(['sluggable.history.table' => $blank]);

    (require __DIR__.'/../../../database/migrations/create_slug_history_table.php')->up();

    expect(Schema::hasTable('slug_history'))->toBeTrue()
        ->and(Schema::hasTable(''))->toBeFalse()
        ->and((new SlugHistory)->getTable())->toBe('slug_history');
})->with(['empty' => '', 'whitespace' => '  ', 'null' => null]);

it('creates the history table under a configured name', function (): void {
    Schema::drop('slug_history');
    config(['sluggable.history.table' => 'retired_slugs']);

    (require __DIR__.'/../../../database/migrations/create_slug_history_table.php')->up();

    expect(Schema::hasTable('retired_slugs'))->toBeTrue();
});

it('refuses a history table name that is not an identifier', function (): void {
    Schema::drop('slug_history');
    config(['sluggable.history.table' => 'slug history; drop']);

    (require __DIR__.'/../../../database/migrations/create_slug_history_table.php')->up();
})->throws(InvalidSlugDefinitionException::class, 'history table');
