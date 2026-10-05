<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Support;

use Illuminate\Support\Str;
use Normalizer;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugFormat;
use RoundlyConsulting\Sluggable\Exceptions\SlugGenerationException;

/**
 * The pure slug pipeline. With the default format its output matches `Str::slug()` for ordinary
 * input up to `max_length` (same step order: ascii → separator flip → dictionary → lower → strip →
 * collapse → trim), so a package migrating onto sluggable keeps its existing slugs. On top of that
 * it bounds the work, strips invisible characters (so `zero\u{200B}width` joins into `zerowidth`),
 * caps words and length, and composes affixes and collision suffixes without ever cutting an affix.
 */
final class Slugger
{
    /** Zero-width and bidi control marks: invisible in a URL bar, so a spoofing vector. */
    private const INVISIBLE = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u';

    /**
     * Steps 1–9 plus the length cap: a bare slug body for one source string.
     */
    public static function slugify(string $source, SlugFormat $format, ?string $language = 'en', ?string $locale = null): string
    {
        $value = self::clean(mb_substr(mb_scrub($source, 'UTF-8'), 0, $format->maxSourceLength), $format->unicode);

        if ($format->slugger !== null) {
            $value = self::custom($value, $format, $locale);
        } else {
            $value = self::pipeline($value, $format, $language);
        }

        return Truncator::truncate(self::limitWords($value, $format), $format->maxLength, $format->separator);
    }

    /**
     * Run an affix (prefix/suffix) through the default pipeline so it cannot inject characters a
     * custom slugger or the host would never have produced.
     */
    public static function affix(string $raw, SlugFormat $format, ?string $language = 'en'): string
    {
        return self::pipeline(self::clean(mb_substr(mb_scrub($raw, 'UTF-8'), 0, $format->maxSourceLength), $format->unicode), $format, $language);
    }

    /**
     * Steps 10–11: join `prefix · body · suffix · collision`, shrinking only the body so the
     * whole value fits `maxLength` — affixes and the collision suffix are never cut, so parts
     * that leave no room for the body throw rather than overflow `maxLength`.
     */
    public static function compose(string $body, SlugFormat $format, string $prefix = '', string $suffix = '', ?string $collision = null): string
    {
        $separator = $format->separator;
        $separatorLength = mb_strlen($separator);

        $fixed = 0;

        foreach ([$prefix, $suffix, (string) $collision] as $part) {
            if ($part !== '') {
                $fixed += mb_strlen($part) + $separatorLength;
            }
        }

        if ($fixed >= $format->maxLength) {
            throw SlugGenerationException::noRoomForBody($fixed, $format->maxLength);
        }

        $body = Truncator::truncate($body, max(1, $format->maxLength - $fixed), $separator);

        $parts = array_filter(
            [$prefix, $body, $suffix, (string) $collision],
            static fn (string $part): bool => $part !== '',
        );

        return implode($separator, $parts);
    }

    /**
     * A custom collision suffix, validated like a custom slugger's output so it cannot emit URL
     * syntax (`/`, `?`, whitespace, …).
     */
    public static function collisionSuffix(string $suffix, SlugFormat $format): string
    {
        if (! self::isAllowed($suffix, $format)) {
            throw SlugGenerationException::invalidSuffix($suffix);
        }

        return $suffix;
    }

    public static function isReserved(string $value, SlugFormat $format): bool
    {
        return $format->reserved !== [] && in_array(mb_strtolower($value), $format->reserved, true);
    }

    /**
     * Whether a value can sit in a URL path segment untouched: no whitespace, control characters,
     * `/`, `?`, `#` or `%`, and not a `.`/`..` dot segment (URL resolution removes those).
     */
    public static function isSafeSegment(string $value): bool
    {
        return $value !== '.' && $value !== '..'
            && mb_check_encoding($value, 'UTF-8')
            && preg_match('/[\s\p{Cc}\/?#%]/u', $value) === 0
            && preg_match(self::INVISIBLE, $value) === 0;
    }

    /**
     * A random slug body — `Str::random()` (never a raw crypto primitive), lowercased unless the
     * definition keeps case.
     */
    public static function random(int $length, bool $lowercase): string
    {
        $value = Str::random($length);

        return $lowercase ? strtolower($value) : $value;
    }

    /**
     * The value exactly as the pipeline would produce it from itself — the `ValidSlug` check.
     */
    public static function isNormalized(string $value, SlugFormat $format, ?string $language = 'en'): bool
    {
        return self::slugify($value, $format, $language) === $value;
    }

    /** Step 2: bounded input is stripped of control and invisible characters (NFKC in unicode mode). */
    private static function clean(string $value, bool $unicode): string
    {
        // Control characters that are not whitespace go; whitespace controls (\t, \n) survive so
        // they still separate words exactly as Str::slug treats them.
        $value = (string) preg_replace('/[^\P{Cc}\s]/u', '', $value);
        $value = (string) preg_replace(self::INVISIBLE, '', $value);

        if ($unicode && class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($value, Normalizer::FORM_KC);
            $value = is_string($normalized) ? $normalized : $value;
        }

        return $value;
    }

    /** Steps 3–8, in `Str::slug()` order. */
    private static function pipeline(string $value, SlugFormat $format, ?string $language): string
    {
        $separator = $format->separator;

        if (! $format->unicode) {
            $value = Str::ascii($value, $language ?? 'en');
        }

        $flip = $separator === '-' ? '_' : '-';
        $value = (string) preg_replace('!['.preg_quote($flip, '!').']+!u', $separator, $value);

        $dictionary = [];

        foreach ($format->dictionary as $key => $replacement) {
            $dictionary[$key] = $separator.$replacement.$separator;
        }

        $value = str_replace(array_keys($dictionary), array_values($dictionary), $value);

        if ($format->lowercase) {
            $value = Str::lower($value);
        }

        $marks = $format->unicode ? '\pM' : '';
        $value = (string) preg_replace('![^'.preg_quote($separator, '!').'\pL\pN'.$marks.'\s]+!u', '', $value);
        $value = (string) preg_replace('!['.preg_quote($separator, '!').'\s]+!u', $separator, $value);

        return Truncator::trimSeparator($value, $separator);
    }

    /** A custom slugger replaces steps 3–8; its output is re-validated so it cannot emit URL syntax. */
    private static function custom(string $value, SlugFormat $format, ?string $locale): string
    {
        $output = ($format->slugger)($value, $locale);

        if (! is_string($output)) {
            throw SlugGenerationException::invalidCustomOutput(get_debug_type($output));
        }

        if (! self::isAllowed($output, $format)) {
            throw SlugGenerationException::invalidCustomOutput($output);
        }

        return Truncator::trimSeparator($output, $format->separator);
    }

    /** Only letters, numbers (marks in unicode mode) and the separator. */
    private static function isAllowed(string $value, SlugFormat $format): bool
    {
        $marks = $format->unicode ? '\pM' : '';

        return preg_match('/^[\pL\pN'.$marks.preg_quote($format->separator, '/').']*$/Du', $value) === 1;
    }

    /** Step 9: keep the first `maxWords` separator-delimited words. */
    private static function limitWords(string $value, SlugFormat $format): string
    {
        if ($format->maxWords === null || $value === '' || $format->separator === '') {
            return $value;
        }

        return implode($format->separator, array_slice(explode($format->separator, $value), 0, $format->maxWords));
    }
}
