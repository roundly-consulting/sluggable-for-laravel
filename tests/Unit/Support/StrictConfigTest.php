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
use RoundlyConsulting\Sluggable\Support\SluggableConfig;

/**
 * A typo in the host's sluggable config fails loudly. Before: a blank or wrong-typed
 * `column`/`source`/`separator`/`language`/`history.table` became the default, a non-string
 * entry in `reserved`, `dictionary` or `locales.supported` was dropped, and a blank or
 * non-string `locales.fallback` became null.
 */
it('refuses a blank or non-string string setting instead of using the default (strict config)', function (string $key, mixed $value, Closure $read): void {
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
        $cases["{$key} blank"] = [$key, '', $read];
        $cases["{$key} array"] = [$key, ['x'], $read];
        $cases["{$key} int"] = [$key, 5, $read];
    }

    return $cases;
});

it('takes the default for an unset string setting (strict config)', function (): void {
    config([
        'sluggable.defaults.column' => null,
        'sluggable.defaults.source' => null,
        'sluggable.defaults.separator' => null,
        'sluggable.defaults.language' => null,
        'sluggable.history.table' => null,
        'sluggable.locales.fallback' => null,
    ]);

    expect(SluggableConfig::defaultColumn())->toBe('slug')
        ->and(SluggableConfig::defaultSource())->toBe('name')
        ->and(SluggableConfig::separator())->toBe('-')
        ->and(SluggableConfig::language())->toBe('en')
        ->and(SluggableConfig::historyTable())->toBe('slug_history')
        ->and(SluggableConfig::fallbackLocale())->toBeNull();
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

it('takes the shipped default for an unset policy instead of throwing missing (strict config)', function (): void {
    config([
        'sluggable.defaults.uniqueness' => null,
        'sluggable.defaults.locale_uniqueness' => null,
        'sluggable.defaults.on_update' => null,
        'sluggable.defaults.manual' => null,
        'sluggable.defaults.empty_source' => null,
        'sluggable.defaults.suffix' => null,
        'sluggable.defaults.target_locales' => null,
        'sluggable.defaults.locale_fallback' => null,
    ]);

    expect(SluggableConfig::uniqueness())->toBe(Uniqueness::Global)
        ->and(SluggableConfig::localeUniqueness())->toBe(LocaleUniqueness::PerLocale)
        ->and(SluggableConfig::onUpdate())->toBe(UpdatePolicy::IfEmpty)
        ->and(SluggableConfig::manual())->toBe(ManualSlugPolicy::Normalize)
        ->and(SluggableConfig::emptySource())->toBe(EmptySourcePolicy::Random)
        ->and(SluggableConfig::suffix())->toBe(SuffixStrategy::Sequential)
        ->and(SluggableConfig::targetLocales())->toBe(TargetLocales::Source)
        ->and(SluggableConfig::localeFallback())->toBe(LocaleFallback::Any);
});
