<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Support;

use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\PackageToolkit\Support\ConfigValidator;
use RoundlyConsulting\Sluggable\Enums\EmptySourcePolicy;
use RoundlyConsulting\Sluggable\Enums\LocaleFallback;
use RoundlyConsulting\Sluggable\Enums\LocaleUniqueness;
use RoundlyConsulting\Sluggable\Enums\ManualSlugPolicy;
use RoundlyConsulting\Sluggable\Enums\SuffixStrategy;
use RoundlyConsulting\Sluggable\Enums\TargetLocales;
use RoundlyConsulting\Sluggable\Enums\Uniqueness;
use RoundlyConsulting\Sluggable\Enums\UpdatePolicy;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\Models\SlugHistory;

/**
 * Every sluggable config key, read LITERALLY (the config-contract scrape cannot see a dynamic
 * `config('sluggable.'.$key)`) and strictly: an unset (null) key takes its shipped default,
 * and a present but invalid value throws `InvalidSlugDefinitionException` naming the key —
 * never a silent fallback.
 */
final class SluggableConfig
{
    /** The shipped defaults, used by the `about` section to say DEFAULT or CUSTOM. */
    private const SHIPPED_DEFAULTS = [
        'column' => 'slug', 'source' => 'name', 'separator' => '-', 'max_length' => 255, 'max_words' => null,
        'language' => 'en', 'dictionary' => ['@' => 'at'], 'lowercase' => true, 'unicode' => false,
        'uniqueness' => 'global', 'locale_uniqueness' => 'per_locale', 'include_trashed' => true,
        'on_create' => true, 'on_update' => 'if_empty', 'manual' => 'normalize', 'empty_source' => 'random',
        'suffix' => 'sequential', 'suffix_start' => 2, 'random_length' => 8, 'target_locales' => 'source',
        'locale_fallback' => 'any',
    ];

    public static function defaultColumn(): string
    {
        return self::string('sluggable.defaults.column', config('sluggable.defaults.column'), 'slug');
    }

    public static function defaultSource(): string
    {
        return self::string('sluggable.defaults.source', config('sluggable.defaults.source'), 'name');
    }

    public static function separator(): string
    {
        return self::string('sluggable.defaults.separator', config('sluggable.defaults.separator'), '-');
    }

    public static function maxLength(): int
    {
        return self::validator()->integer('sluggable.defaults.max_length', 255, min: 8, max: 2048);
    }

    public static function maxWords(): ?int
    {
        if (config('sluggable.defaults.max_words') === null) {
            return null;
        }

        return self::validator()->integer('sluggable.defaults.max_words', 1000, min: 1, max: 1000);
    }

    public static function language(): string
    {
        return self::string('sluggable.defaults.language', config('sluggable.defaults.language'), 'en');
    }

    /** @return array<string, string> */
    public static function dictionary(): array
    {
        return self::stringMap('sluggable.defaults.dictionary', config('sluggable.defaults.dictionary') ?? ['@' => 'at']);
    }

    public static function lowercase(): bool
    {
        return self::validator()->boolean('sluggable.defaults.lowercase', true);
    }

    public static function unicode(): bool
    {
        return self::validator()->boolean('sluggable.defaults.unicode');
    }

    public static function uniqueness(): Uniqueness
    {
        return self::validator()->enum('sluggable.defaults.uniqueness', Uniqueness::class, Uniqueness::Global);
    }

    public static function localeUniqueness(): LocaleUniqueness
    {
        return self::validator()->enum('sluggable.defaults.locale_uniqueness', LocaleUniqueness::class, LocaleUniqueness::PerLocale);
    }

    public static function includeTrashed(): bool
    {
        return self::validator()->boolean('sluggable.defaults.include_trashed', true);
    }

    public static function onCreate(): bool
    {
        return self::validator()->boolean('sluggable.defaults.on_create', true);
    }

    public static function onUpdate(): UpdatePolicy
    {
        return self::validator()->enum('sluggable.defaults.on_update', UpdatePolicy::class, UpdatePolicy::IfEmpty);
    }

    public static function manual(): ManualSlugPolicy
    {
        return self::validator()->enum('sluggable.defaults.manual', ManualSlugPolicy::class, ManualSlugPolicy::Normalize);
    }

    public static function emptySource(): EmptySourcePolicy
    {
        return self::validator()->enum('sluggable.defaults.empty_source', EmptySourcePolicy::class, EmptySourcePolicy::Random);
    }

    public static function suffix(): SuffixStrategy
    {
        return self::validator()->enum('sluggable.defaults.suffix', SuffixStrategy::class, SuffixStrategy::Sequential);
    }

    public static function suffixStart(): int
    {
        return self::validator()->integer('sluggable.defaults.suffix_start', 2, min: 1, max: 1000);
    }

    public static function randomLength(): int
    {
        return self::validator()->integer('sluggable.defaults.random_length', 8, min: 4, max: 32);
    }

    public static function targetLocales(): TargetLocales
    {
        return self::validator()->enum('sluggable.defaults.target_locales', TargetLocales::class, TargetLocales::Source);
    }

    public static function localeFallback(): LocaleFallback
    {
        return self::validator()->enum('sluggable.defaults.locale_fallback', LocaleFallback::class, LocaleFallback::Any);
    }

    /** @return list<string> lowercased */
    public static function reserved(): array
    {
        return array_map(mb_strtolower(...), self::stringList('sluggable.reserved', config('sluggable.reserved') ?? []));
    }

    /** @return list<string>|null */
    public static function supportedLocales(): ?array
    {
        $supported = config('sluggable.locales.supported');

        return $supported === null ? null : self::stringList('sluggable.locales.supported', $supported);
    }

    public static function fallbackLocale(): ?string
    {
        $fallback = config('sluggable.locales.fallback');

        return $fallback === null ? null : self::string('sluggable.locales.fallback', $fallback, '');
    }

    public static function bindingKeyFallback(): bool
    {
        return self::validator()->boolean('sluggable.binding.key_fallback');
    }

    public static function maxSourceLength(): int
    {
        return self::validator()->integer('sluggable.limits.max_source_length', 2000, min: 64, max: 100000);
    }

    public static function sequentialProbes(): int
    {
        return self::validator()->integer('sluggable.limits.sequential_probes', 50, min: 1, max: 1000);
    }

    public static function probeBatch(): int
    {
        return self::validator()->integer('sluggable.limits.probe_batch', 10, min: 1, max: 100);
    }

    public static function randomAttempts(): int
    {
        return self::validator()->integer('sluggable.limits.random_attempts', 10, min: 1, max: 100);
    }

    public static function retries(): int
    {
        return self::validator()->integer('sluggable.concurrency.retries', 3, min: 0, max: 20);
    }

    public static function historyEnabled(): bool
    {
        return self::validator()->boolean('sluggable.history.enabled');
    }

    public static function historyRedirect(): bool
    {
        return self::validator()->boolean('sluggable.history.redirect', true);
    }

    public static function historyRedirectStatus(): int
    {
        $status = self::validator()->integer('sluggable.history.redirect_status', 301, min: 301, max: 308);

        if (! in_array($status, [301, 302, 307, 308], true)) {
            throw InvalidSlugDefinitionException::invalidOption('sluggable.history.redirect_status', 'use 301, 302, 307 or 308');
        }

        return $status;
    }

    public static function historyAvoidReuse(): bool
    {
        return self::validator()->boolean('sluggable.history.avoid_reuse');
    }

    public static function historyTable(): string
    {
        return IdentifierGuard::identifier(self::string('sluggable.history.table', config('sluggable.history.table'), 'slug_history'), 'history table');
    }

    /** @return class-string<SlugHistory> */
    public static function historyModel(): string
    {
        $model = config('sluggable.history.model') ?? SlugHistory::class;

        if (! is_string($model) || ! is_a($model, SlugHistory::class, true)) {
            throw InvalidSlugDefinitionException::invalidOption('sluggable.history.model', 'it must name a subclass of '.SlugHistory::class);
        }

        return $model;
    }

    public static function pruneAfterDays(): ?int
    {
        if (config('sluggable.history.prune_after_days') === null) {
            return null;
        }

        return self::validator()->integer('sluggable.history.prune_after_days', 365, min: 1, max: 36500);
    }

    public static function keyType(): KeyType
    {
        return KeyType::fromConfig('sluggable.key_type');
    }

    /** Whether any definition default differs from what the package ships. */
    public static function defaultsAreCustomised(): bool
    {
        $configured = config('sluggable.defaults');

        if (! is_array($configured)) {
            return false;
        }

        foreach (self::SHIPPED_DEFAULTS as $key => $shipped) {
            if (array_key_exists($key, $configured) && $configured[$key] != $shipped) {
                return true;
            }
        }

        return false;
    }

    private static function validator(): ConfigValidator
    {
        return Config::using(InvalidSlugDefinitionException::class);
    }

    /**
     * The default when unset; otherwise a non-empty string, or the package exception —
     * never a silent fallback to the default.
     */
    private static function string(string $key, mixed $value, string $default): string
    {
        if ($value === null) {
            return $default;
        }

        if (! is_string($value) || trim($value) === '') {
            throw InvalidSlugDefinitionException::invalidOption($key, 'it must be a non-empty string, '.self::describe($value).' given');
        }

        return $value;
    }

    /**
     * A list of non-empty strings; a non-list or a bad entry throws rather than being
     * dropped.
     *
     * @return list<string>
     */
    private static function stringList(string $key, mixed $value): array
    {
        if (! is_array($value)) {
            throw InvalidSlugDefinitionException::invalidOption($key, 'it must be a list of strings, '.self::describe($value).' given');
        }

        $list = [];

        foreach ($value as $index => $item) {
            $list[] = self::string("{$key}.{$index}", $item ?? '', '');
        }

        return $list;
    }

    /**
     * A string => string map; a non-map or a bad entry throws rather than being dropped.
     *
     * @return array<string, string>
     */
    private static function stringMap(string $key, mixed $value): array
    {
        if (! is_array($value)) {
            throw InvalidSlugDefinitionException::invalidOption($key, 'it must be a string => string map, '.self::describe($value).' given');
        }

        $map = [];

        foreach ($value as $search => $replacement) {
            if (! is_string($search) || ! is_string($replacement)) {
                throw InvalidSlugDefinitionException::invalidOption(
                    "{$key}.{$search}",
                    'every entry must map a string to a string, '.self::describe($replacement).' given',
                );
            }

            $map[$search] = $replacement;
        }

        return $map;
    }

    private static function describe(mixed $value): string
    {
        return is_scalar($value) ? '['.var_export($value, true).']' : '['.get_debug_type($value).']';
    }
}
