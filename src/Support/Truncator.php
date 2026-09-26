<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Support;

/**
 * Multibyte-safe truncation that prefers a word boundary.
 */
final class Truncator
{
    public static function truncate(string $value, int $limit, string $separator): string
    {
        if ($limit <= 0) {
            return '';
        }

        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        $cut = mb_substr($value, 0, $limit);

        // The cut already sits on a boundary when the next character starts a separator.
        if (! str_starts_with(mb_substr($value, $limit), $separator)) {
            $boundary = mb_strrpos($cut, $separator);

            // Only honour the boundary when it keeps at least half of the budget; otherwise a
            // single long first word would collapse the slug to almost nothing.
            if ($boundary !== false && $boundary >= intdiv($limit, 2)) {
                $cut = mb_substr($cut, 0, $boundary);
            }
        }

        return self::trimSeparator($cut, $separator);
    }

    /**
     * `trim()` by the separator's characters. The list is deduplicated first: `..` inside a trim()
     * character list is PHP's range operator, so a `..`/`...` separator would otherwise warn.
     */
    public static function trimSeparator(string $value, string $separator): string
    {
        return trim($value, count_chars($separator, 3));
    }
}
