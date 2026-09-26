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
 * `config('sluggable.'.$key)`), validated where a typo would otherwise silently downgrade.
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
        return self::string(config('sluggable.defaults.column'), 'slug');
    }

    public static function defaultSource(): string
    {
        return self::string(config('sluggable.defaults.source'), 'name');
    }

    public static function separator(): string
    {
        return self::string(config('sluggable.defaults.separator'), '-');
    }

    public static function maxLength(): int
    {
        return self::validator()->intBetween('sluggable.defaults.max_length', 8, 2048, 255);
    }

    public static function maxWords(): ?int
    {
        if (config('sluggable.defaults.max_words') === null) {
            return null;
        }

        return self::validator()->intBetween('sluggable.defaults.max_words', 1, 1000, 1000);
    }

    public static function language(): string
    {
        return self::string(config('sluggable.defaults.language'), 'en');
    }

    /** @return array<string, string> */
    public static function dictionary(): array
    {
        return self::stringMap(config('sluggable.defaults.dictionary', ['@' => 'at']));
    }

    public static function lowercase(): bool
    {
        return Config::boolean('sluggable.defaults.lowercase', true);
    }

    public static function unicode(): bool
    {
        return Config::boolean('sluggable.defaults.unicode');
    }

    public static function uniqueness(): Uniqueness
    {
        return self::validator()->enum('sluggable.defaults.uniqueness', Uniqueness::class);
    }

    public static function localeUniqueness(): LocaleUniqueness
    {
        return self::validator()->enum('sluggable.defaults.locale_uniqueness', LocaleUniqueness::class);
    }

    public static function includeTrashed(): bool
    {
        return Config::boolean('sluggable.defaults.include_trashed', true);
    }

    public static function onCreate(): bool
    {
        return Config::boolean('sluggable.defaults.on_create', true);
    }

    public static function onUpdate(): UpdatePolicy
    {
        return self::validator()->enum('sluggable.defaults.on_update', UpdatePolicy::class);
    }

    public static function manual(): ManualSlugPolicy
    {
        return self::validator()->enum('sluggable.defaults.manual', ManualSlugPolicy::class);
    }

    public static function emptySource(): EmptySourcePolicy
    {
        return self::validator()->enum('sluggable.defaults.empty_source', EmptySourcePolicy::class);
    }

    public static function suffix(): SuffixStrategy
    {
        return self::validator()->enum('sluggable.defaults.suffix', SuffixStrategy::class);
    }

    public static function suffixStart(): int
    {
        return self::validator()->intBetween('sluggable.defaults.suffix_start', 1, 1000, 2);
    }

    public static function randomLength(): int
    {
        return self::validator()->intBetween('sluggable.defaults.random_length', 4, 32, 8);
    }

    public static function targetLocales(): TargetLocales
    {
        return self::validator()->enum('sluggable.defaults.target_locales', TargetLocales::class);
    }

    public static function localeFallback(): LocaleFallback
    {
        return self::validator()->enum('sluggable.defaults.locale_fallback', LocaleFallback::class);
    }

    /** @return list<string> lowercased */
    public static function reserved(): array
    {
        return array_map(mb_strtolower(...), self::stringList(config('sluggable.reserved', [])));
    }

    /** @return list<string>|null */
    public static function supportedLocales(): ?array
    {
        $supported = config('sluggable.locales.supported');

        return $supported === null ? null : self::stringList($supported);
    }

    public static function fallbackLocale(): ?string
    {
        $fallback = config('sluggable.locales.fallback');

        return is_string($fallback) && $fallback !== '' ? $fallback : null;
    }

    public static function bindingKeyFallback(): bool
    {
        return Config::boolean('sluggable.binding.key_fallback');
    }

    public static function maxSourceLength(): int
    {
        return self::validator()->intBetween('sluggable.limits.max_source_length', 64, 100000, 2000);
    }

    public static function sequentialProbes(): int
    {
        return self::validator()->intBetween('sluggable.limits.sequential_probes', 1, 1000, 50);
    }

    public static function probeBatch(): int
    {
        return self::validator()->intBetween('sluggable.limits.probe_batch', 1, 100, 10);
    }

    public static function randomAttempts(): int
    {
        return self::validator()->intBetween('sluggable.limits.random_attempts', 1, 100, 10);
    }

    public static function retries(): int
    {
        return self::validator()->intBetween('sluggable.concurrency.retries', 0, 20, 3);
    }

    public static function historyEnabled(): bool
    {
        return Config::boolean('sluggable.history.enabled');
    }

    public static function historyRedirect(): bool
    {
        return Config::boolean('sluggable.history.redirect', true);
    }

    public static function historyRedirectStatus(): int
    {
        $status = self::validator()->intBetween('sluggable.history.redirect_status', 301, 308, 301);

        if (! in_array($status, [301, 302, 307, 308], true)) {
            throw InvalidSlugDefinitionException::invalidOption('sluggable.history.redirect_status', 'use 301, 302, 307 or 308');
        }

        return $status;
    }

    public static function historyAvoidReuse(): bool
    {
        return Config::boolean('sluggable.history.avoid_reuse');
    }

    public static function historyTable(): string
    {
        return IdentifierGuard::identifier(self::string(config('sluggable.history.table'), 'slug_history'), 'history table');
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

        return self::validator()->intBetween('sluggable.history.prune_after_days', 1, 36500, 365);
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

    private static function string(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && $item !== ''));
    }

    /** @return array<string, string> */
    private static function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $replacement) {
            if (is_string($key) && is_string($replacement)) {
                $map[$key] = $replacement;
            }
        }

        return $map;
    }
}
