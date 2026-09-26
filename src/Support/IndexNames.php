<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Support;

use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;

/**
 * Deterministic names for the unique indexes and MySQL generated columns sluggable creates, so a
 * rerun finds its own objects and the collision matcher can recognise them in error messages.
 */
final class IndexNames
{
    /** MySQL caps identifiers at 64 chars; stay clear of it. */
    private const MAX = 60;

    public static function index(string $table, string $column, ?string $locale = null): string
    {
        $suffix = $locale === null ? '' : '_'.self::normalizeLocale($locale);

        return self::shorten(strtolower("{$table}_{$column}{$suffix}_slug_unique"));
    }

    /** MySQL generated column backing a per-locale unique index. */
    public static function localeColumn(string $column, string $locale): string
    {
        return self::shorten($column.'__'.self::normalizeLocale($locale));
    }

    /** MySQL generated column backing an exclude-trashed string index. */
    public static function liveColumn(string $column): string
    {
        return self::shorten($column.'__live');
    }

    public static function normalizeLocale(string $locale): string
    {
        return strtolower(str_replace('-', '_', $locale));
    }

    /**
     * Reject a locale set whose members normalise to the same name (`pt-BR` / `pt_BR`): both would
     * map onto one index or generated column.
     *
     * @param  list<string>  $locales
     */
    public static function assertDistinct(array $locales): void
    {
        $seen = [];

        foreach ($locales as $locale) {
            $normalized = self::normalizeLocale($locale);

            if (isset($seen[$normalized]) && $seen[$normalized] !== $locale) {
                throw InvalidSlugDefinitionException::localeCollision($seen[$normalized], $locale);
            }

            $seen[$normalized] = $locale;
        }
    }

    public static function shorten(string $name): string
    {
        if (strlen($name) <= self::MAX) {
            return $name;
        }

        // crc32 only shortens a name deterministically; nothing security-relevant is hashed.
        return substr($name, 0, 51).'_'.sprintf('%08x', crc32($name));
    }
}
