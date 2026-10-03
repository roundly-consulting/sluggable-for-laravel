<?php

declare(strict_types=1);

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
use RoundlyConsulting\Sluggable\Support\SluggableConfig;

/**
 * A typo in the host's sluggable config fails loudly. Before: a wrong-typed
 * `column`/`source`/`separator`/`language`/`history.table` became the default, a non-string
 * entry in `reserved`, `dictionary` or `locales.supported` was dropped, and a non-string
 * `locales.fallback` became null. A blank value (a host's `KEY=`) is not set and takes the
 * default — for the optional `locales.fallback`, `max_words` and `prune_after_days` that
 * default is null (the app fallback locale, no cap, never prune).
 */
it('refuses a non-string string setting instead of using the default (strict config)', function (string $key, mixed $value, Closure $read): void {
    config([$key => $value]);

    expect($read)->toThrow(InvalidSlugDefinitionException::class, $key);
})->with(function (): array {
    $reads = [
        'sluggable.defaults.column' => fn () => SluggableConfig::defaultColumn(),
        'sluggable.defaults.source' => fn () => SluggableConfig::defaultSource(),
        'sluggable.defaults.separator' => fn () => SluggableConfig::separator(),
        'sluggable.defaults.language' => fn () => SluggableConfig::language(),
        'sluggable.history.table' => fn () => SluggableConfig::historyTable(),
        'sluggable.locales.fallback' => fn () => SluggableConfig::fallbackLocale(),
    ];

    $cases = [];

    foreach ($reads as $key => $read) {
        $cases["{$key} array"] = [$key, ['x'], $read];
        $cases["{$key} int"] = [$key, 5, $read];
    }

    return $cases;
});

it('takes the default for an unset or blank string setting (strict config)', function (?string $unset): void {
    config([
        'sluggable.defaults.column' => $unset,
        'sluggable.defaults.source' => $unset,
        'sluggable.defaults.separator' => $unset,
        'sluggable.defaults.language' => $unset,
        'sluggable.history.table' => $unset,
        'sluggable.locales.fallback' => $unset,
    ]);

    expect(SluggableConfig::defaultColumn())->toBe('slug')
        ->and(SluggableConfig::defaultSource())->toBe('name')
        ->and(SluggableConfig::separator())->toBe('-')
        ->and(SluggableConfig::language())->toBe('en')
        ->and(SluggableConfig::historyTable())->toBe('slug_history')
        ->and(SluggableConfig::fallbackLocale())->toBeNull();
})->with(['absent' => null, 'empty' => '', 'whitespace' => '  ']);

it('reads a blank optional limit as not set, never switching it on (strict config)', function (string $blank): void {
    config([
        'sluggable.defaults.max_words' => $blank,
        'sluggable.history.prune_after_days' => $blank,
        'sluggable.history.model' => $blank,
    ]);

    expect(SluggableConfig::maxWords())->toBeNull()
        ->and(SluggableConfig::pruneAfterDays())->toBeNull()
        ->and(SluggableConfig::historyModel())->toBe(SlugHistory::class);
})->with(['empty' => '', 'whitespace' => '  ']);

it('still refuses a junk optional limit (strict config)', function (string $key, Closure $read): void {
    config([$key => 'five']);

    expect($read)->toThrow(InvalidSlugDefinitionException::class, $key);
})->with([
    'max words' => ['sluggable.defaults.max_words', fn () => SluggableConfig::maxWords()],
    'prune after days' => ['sluggable.history.prune_after_days', fn () => SluggableConfig::pruneAfterDays()],
    'history model' => ['sluggable.history.model', fn () => SluggableConfig::historyModel()],
]);

it('reads a blank shipped default as not customised (strict config)', function (): void {
    config(['sluggable.defaults.separator' => '', 'sluggable.defaults.on_update' => ' ']);

    expect(SluggableConfig::defaultsAreCustomised())->toBeFalse();

    config(['sluggable.defaults.separator' => '_']);

    expect(SluggableConfig::defaultsAreCustomised())->toBeTrue();
});

it('refuses a non-list or a non-string entry in a string list (strict config)', function (string $key, mixed $value, Closure $read): void {
    config([$key => $value]);

    expect($read)->toThrow(InvalidSlugDefinitionException::class, $key);
})->with([
    'reserved not a list' => ['sluggable.reserved', 'admin', fn () => SluggableConfig::reserved()],
    'reserved int entry' => ['sluggable.reserved', ['admin', 42], fn () => SluggableConfig::reserved()],
    'reserved blank entry' => ['sluggable.reserved', ['admin', ''], fn () => SluggableConfig::reserved()],
    'supported not a list' => ['sluggable.locales.supported', 'en', fn () => SluggableConfig::supportedLocales()],
    'supported array entry' => ['sluggable.locales.supported', ['en', ['sk']], fn () => SluggableConfig::supportedLocales()],
]);

it('reads valid string lists and an unset one (strict config)', function (): void {
    config(['sluggable.reserved' => ['Admin', 'login'], 'sluggable.locales.supported' => ['en', 'sk']]);

    expect(SluggableConfig::reserved())->toBe(['admin', 'login'])
        ->and(SluggableConfig::supportedLocales())->toBe(['en', 'sk']);

    config(['sluggable.reserved' => null, 'sluggable.locales.supported' => null]);

    expect(SluggableConfig::reserved())->toBe([])
        ->and(SluggableConfig::supportedLocales())->toBeNull();
});

it('refuses a non-map dictionary or a non-string entry in it (strict config)', function (mixed $dictionary): void {
    config(['sluggable.defaults.dictionary' => $dictionary]);

    expect(fn () => SluggableConfig::dictionary())
        ->toThrow(InvalidSlugDefinitionException::class, 'sluggable.defaults.dictionary');
})->with([
    'a string' => '@=at',
    'int replacement' => [['@' => 'at', '&' => 1]],
    'list entry' => [['at']],
]);

it('reads a valid dictionary and the default when unset (strict config)', function (): void {
    config(['sluggable.defaults.dictionary' => ['&' => 'and']]);
    expect(SluggableConfig::dictionary())->toBe(['&' => 'and']);

    config(['sluggable.defaults.dictionary' => null]);
    expect(SluggableConfig::dictionary())->toBe(['@' => 'at']);
});

it('takes the shipped default for an unset or blank policy instead of throwing missing (strict config)', function (?string $unset): void {
    config([
        'sluggable.defaults.uniqueness' => $unset,
        'sluggable.defaults.locale_uniqueness' => $unset,
        'sluggable.defaults.on_update' => $unset,
        'sluggable.defaults.manual' => $unset,
        'sluggable.defaults.empty_source' => $unset,
        'sluggable.defaults.suffix' => $unset,
        'sluggable.defaults.target_locales' => $unset,
        'sluggable.defaults.locale_fallback' => $unset,
    ]);

    expect(SluggableConfig::uniqueness())->toBe(Uniqueness::Global)
        ->and(SluggableConfig::localeUniqueness())->toBe(LocaleUniqueness::PerLocale)
        ->and(SluggableConfig::onUpdate())->toBe(UpdatePolicy::IfEmpty)
        ->and(SluggableConfig::manual())->toBe(ManualSlugPolicy::Normalize)
        ->and(SluggableConfig::emptySource())->toBe(EmptySourcePolicy::Random)
        ->and(SluggableConfig::suffix())->toBe(SuffixStrategy::Sequential)
        ->and(SluggableConfig::targetLocales())->toBe(TargetLocales::Source)
        ->and(SluggableConfig::localeFallback())->toBe(LocaleFallback::Any);
})->with(['absent' => null, 'empty' => '', 'whitespace' => '  ']);
