<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Fluent;
use RoundlyConsulting\Sluggable\Support\IdentifierGuard;
use RoundlyConsulting\Sluggable\Support\IndexNames;

/**
 * `$table->slug()`, `$table->localizedSlug()` and `$table->uniqueSlug()`. Registered at register()
 * time (migrations can run before boot) and guarded by hasMacro(), so a double registration is a
 * no-op. Made visible to PHPStan by the package's extension.neon.
 */
final class BlueprintMacros
{
    public static function register(): void
    {
        if (! Blueprint::hasMacro('slug')) {
            Blueprint::macro('slug', function (string $column = 'slug', int $length = 255): ColumnDefinition {
                /** @var Blueprint $this */
                return BlueprintMacros::slug($this, $column, $length);
            });
        }

        if (! Blueprint::hasMacro('localizedSlug')) {
            Blueprint::macro('localizedSlug', function (string $column = 'slug'): ColumnDefinition {
                /** @var Blueprint $this */
                return BlueprintMacros::localizedSlug($this, $column);
            });
        }

        if (! Blueprint::hasMacro('uniqueSlug')) {
            /** @return Fluent<string, mixed> */
            Blueprint::macro('uniqueSlug', function (string $column = 'slug', array $scope = []): Fluent {
                /** @var Blueprint $this */
                return BlueprintMacros::uniqueSlug($this, $column, array_values(array_filter($scope, is_string(...))));
            });
        }
    }

    public static function slug(Blueprint $table, string $column = 'slug', int $length = 255): ColumnDefinition
    {
        return $table->string(IdentifierGuard::identifier($column, 'column'), $length);
    }

    /** jsonb on Postgres, json on MySQL, text on SQLite — what the grammars map `jsonb()` to. */
    public static function localizedSlug(Blueprint $table, string $column = 'slug'): ColumnDefinition
    {
        return $table->jsonb(IdentifierGuard::identifier($column, 'column'));
    }

    /**
     * A portable composite unique index for a string slug that counts trashed rows as taken; any
     * other shape (locale maps, exclude-trashed) needs SlugIndexes after Schema::create().
     *
     * @param  list<string>  $scope
     * @return Fluent<string, mixed>
     */
    public static function uniqueSlug(Blueprint $table, string $column = 'slug', array $scope = []): Fluent
    {
        foreach ([$column, ...$scope] as $identifier) {
            IdentifierGuard::identifier($identifier, 'column');
        }

        return $table->unique([...$scope, $column], IndexNames::index($table->getTable(), $column));
    }
}
