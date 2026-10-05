<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\DataTransferObjects;

use RoundlyConsulting\Sluggable\Enums\SlugStorage;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\Support\IdentifierGuard;

/**
 * The unique index one slug column needs. Prefer `SlugIndexes::forModel()`, which derives this
 * from the model's definitions; hand-written specs must mirror the definition (notably
 * `includeTrashed`, which defaults to `true` like `defaults.include_trashed`).
 */
final readonly class SlugIndexSpec
{
    /**
     * @param  list<string>  $scope
     * @param  list<string>|null  $locales  null = SlugLocales::supported()
     * @param  int|null  $maxLength  the definition's maxLength; sizes MySQL's per-locale generated
     *                               columns (null = 255)
     */
    public function __construct(
        public string $table,
        public string $column,
        public SlugStorage $storage = SlugStorage::String,
        public array $scope = [],
        public bool $includeTrashed = true,
        public ?array $locales = null,
        public ?string $connection = null,
        public string $deletedAtColumn = 'deleted_at',
        public ?int $maxLength = null,
    ) {
        if ($maxLength !== null && ($maxLength < 1 || $maxLength > 2048)) {
            throw InvalidSlugDefinitionException::invalidOption('maxLength', 'use 1-2048 characters');
        }

        IdentifierGuard::identifier($table, 'table');
        IdentifierGuard::identifier($column, 'column');
        IdentifierGuard::identifier($deletedAtColumn, 'column');

        foreach ($scope as $scopeColumn) {
            IdentifierGuard::identifier($scopeColumn, 'scope column');
        }

        foreach ($locales ?? [] as $locale) {
            IdentifierGuard::locale($locale);
        }
    }

    /** @param list<string> $scope */
    public static function string(string $table, string $column = 'slug', array $scope = [], bool $includeTrashed = true, ?string $connection = null, string $deletedAtColumn = 'deleted_at'): self
    {
        return new self($table, $column, SlugStorage::String, $scope, $includeTrashed, null, $connection, $deletedAtColumn);
    }

    /**
     * @param  list<string>  $scope
     * @param  list<string>|null  $locales
     */
    public static function localeMap(string $table, string $column = 'slug', array $scope = [], bool $includeTrashed = true, ?array $locales = null, ?string $connection = null, string $deletedAtColumn = 'deleted_at', ?int $maxLength = null): self
    {
        return new self($table, $column, SlugStorage::LocaleMap, $scope, $includeTrashed, $locales, $connection, $deletedAtColumn, $maxLength);
    }
}
