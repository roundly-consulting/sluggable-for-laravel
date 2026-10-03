<?php

declare(strict_types=1);

use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\Exceptions\SluggableException;
use RoundlyConsulting\Sluggable\Support\SluggableConfig;

/**
 * A typo in a switch must not read as its default: every config value a definition is built
 * from fails the same way, with the package's own exception, so a host catching
 * `SluggableException` catches a misconfigured switch too.
 */
it('throws the package exception for a switch typo (strict config)', function (string $key, Closure $read): void {
    config([$key => 'disabled']);

    expect($read)->toThrow(
        InvalidSlugDefinitionException::class,
        "Configuration value [{$key}] must be a boolean (true/false, 1/0, on/off or yes/no), [disabled] given.",
    );
})->with([
    'lowercase' => ['sluggable.defaults.lowercase', fn (): bool => SluggableConfig::lowercase()],
    'unicode' => ['sluggable.defaults.unicode', fn (): bool => SluggableConfig::unicode()],
    'include trashed' => ['sluggable.defaults.include_trashed', fn (): bool => SluggableConfig::includeTrashed()],
    'on create' => ['sluggable.defaults.on_create', fn (): bool => SluggableConfig::onCreate()],
    'key fallback' => ['sluggable.binding.key_fallback', fn (): bool => SluggableConfig::bindingKeyFallback()],
    'history' => ['sluggable.history.enabled', fn (): bool => SluggableConfig::historyEnabled()],
    'history redirect' => ['sluggable.history.redirect', fn (): bool => SluggableConfig::historyRedirect()],
    'avoid reuse' => ['sluggable.history.avoid_reuse', fn (): bool => SluggableConfig::historyAvoidReuse()],
]);

it('reads env-word switches as booleans', function (string $value, bool $expected): void {
    config(['sluggable.history.enabled' => $value]);

    expect(SluggableConfig::historyEnabled())->toBe($expected);
})->with([
    ['off', false],
    ['no', false],
    ['0', false],
    ['on', true],
    ['yes', true],
    ['1', true],
]);

it('falls back to the default only when a switch is unset', function (): void {
    config(['sluggable.history.redirect' => null, 'sluggable.history.enabled' => null]);

    expect(SluggableConfig::historyRedirect())->toBeTrue()
        ->and(SluggableConfig::historyEnabled())->toBeFalse();
});

it('throws the package exception for an enum typo', function (): void {
    config(['sluggable.defaults.uniqueness' => 'globl']);

    expect(fn () => SluggableConfig::uniqueness())->toThrow(SluggableException::class, 'sluggable.defaults.uniqueness');
});
