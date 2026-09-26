<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Support;

use RoundlyConsulting\Sluggable\Exceptions\InvalidLocaleException;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;

/**
 * Allowlists for every value that reaches a JSON path or DDL: locale keys (from routes, requests
 * and definitions) and table/column names. Nothing outside these patterns ever reaches SQL.
 */
final class IdentifierGuard
{
    /** Same shape translatable accepts, so one host locale set passes both packages. */
    public const LOCALE = '/^[a-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*$/D';

    /** 35 chars fits `slug_history.locale` and keeps index names bounded. */
    public const LOCALE_MAX = 35;

    /** A plain identifier, optionally qualified once (`table.column`). */
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D';

    public static function isLocale(mixed $locale): bool
    {
        return is_string($locale)
            && strlen($locale) <= self::LOCALE_MAX
            && preg_match(self::LOCALE, $locale) === 1;
    }

    public static function locale(mixed $locale): string
    {
        if (! is_string($locale) || ! self::isLocale($locale)) {
            throw InvalidLocaleException::malformed(is_string($locale) ? $locale : get_debug_type($locale));
        }

        return $locale;
    }

    public static function isIdentifier(string $identifier): bool
    {
        return preg_match(self::IDENTIFIER, $identifier) === 1;
    }

    public static function identifier(string $identifier, string $what = 'identifier'): string
    {
        if (! self::isIdentifier($identifier)) {
            throw InvalidSlugDefinitionException::invalidIdentifier($identifier, $what);
        }

        return $identifier;
    }

    /**
     * A column reference that may be table-qualified (`products.slug`), as Laravel hands scoped
     * child bindings over.
     */
    public static function qualifiedIdentifier(string $identifier, string $what = 'column'): string
    {
        foreach (explode('.', $identifier, 2) as $segment) {
            self::identifier($segment, $what);
        }

        return $identifier;
    }
}
