<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Support;

use Illuminate\Database\Query\Builder;

/**
 * `column->locale` references for locale-map slugs. Locales are allowlisted before they are ever
 * joined into a path, so the grammar-wrapped expression cannot carry injected SQL.
 */
final class JsonPath
{
    /** The Laravel JSON selector (`slug->en`, or qualified `products.slug->en`). */
    public static function of(string $column, string $locale): string
    {
        IdentifierGuard::qualifiedIdentifier($column);

        return $column.'->'.IdentifierGuard::locale($locale);
    }

    /** The grammar-wrapped SQL for the selector, for raw ORDER BY / SELECT fragments. */
    public static function wrapped(Builder $query, string $column, string $locale): string
    {
        return $query->getGrammar()->wrap(self::of($column, $locale));
    }
}
