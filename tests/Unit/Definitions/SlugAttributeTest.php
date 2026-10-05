<?php

declare(strict_types=1);

use RoundlyConsulting\Sluggable\Attributes\Slug;
use RoundlyConsulting\Sluggable\Enums\EmptySourcePolicy;
use RoundlyConsulting\Sluggable\Enums\LocaleFallback;
use RoundlyConsulting\Sluggable\Enums\LocaleUniqueness;
use RoundlyConsulting\Sluggable\Enums\ManualSlugPolicy;
use RoundlyConsulting\Sluggable\Enums\SlugStorage;
use RoundlyConsulting\Sluggable\Enums\SuffixStrategy;
use RoundlyConsulting\Sluggable\Enums\TargetLocales;
use RoundlyConsulting\Sluggable\Enums\Uniqueness;
use RoundlyConsulting\Sluggable\Enums\UpdatePolicy;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\Support\SlugOptionsRegistry;
use RoundlyConsulting\Sluggable\Tests\Fixtures\Article;
use RoundlyConsulting\Sluggable\Tests\Fixtures\AttributeItem;
use RoundlyConsulting\Sluggable\Tests\Fixtures\ConflictingItem;
use RoundlyConsulting\Sluggable\Tests\Fixtures\DefaultThing;
use RoundlyConsulting\Sluggable\Tests\Fixtures\LocalizedPage;

it('maps every attribute argument onto the definition', function (): void {
    $attribute = new Slug(
        column: 'slug',
        from: ['title', 'name'],
        separator: '_',
        maxLength: 50,
        maxWords: 4,
        language: 'de',
        unicode: true,
        lowercase: false,
        uniqueness: Uniqueness::Global,
        includeTrashed: false,
        onUpdate: UpdatePolicy::Always,
        manual: ManualSlugPolicy::Verbatim,
        emptySource: EmptySourcePolicy::Skip,
        suffix: SuffixStrategy::Sequential,
        suffixStart: 1,
        randomLength: 6,
        fallback: LocaleFallback::None,
        routeKey: true,
        bindByKeyFallback: true,
        keepHistory: true,
        redirectFromHistory: false,
        reserved: ['new'],
    );

    $definition = $attribute->toDefinition()->resolve(new Article);

    expect($definition->format->separator)->toBe('_')
        ->and($definition->format->maxLength)->toBe(50)
        ->and($definition->format->maxWords)->toBe(4)
        ->and($definition->format->unicode)->toBeTrue()
        ->and($definition->format->lowercase)->toBeFalse()
        ->and($definition->format->reserved)->toContain('new')
        ->and($definition->languageFor(null))->toBe('de')
        ->and($definition->uniqueness)->toBe(Uniqueness::Global)
        ->and($definition->includeTrashed)->toBeFalse()
        ->and($definition->onUpdate)->toBe(UpdatePolicy::Always)
        ->and($definition->manual)->toBe(ManualSlugPolicy::Verbatim)
        ->and($definition->emptySource)->toBe(EmptySourcePolicy::Skip)
        ->and($definition->suffixGenerator->suffix('x', 1, $definition))->toBe('1')
        ->and($definition->randomLength)->toBe(6)
        ->and($definition->fallback)->toBe(LocaleFallback::None)
        ->and($definition->routeKey)->toBeTrue()
        ->and($definition->bindByKeyFallback)->toBeTrue()
        ->and($definition->keepHistory)->toBeTrue()
        ->and($definition->redirectFromHistory)->toBeFalse();
});

it('maps locale options and the remaining strategies', function (): void {
    $definition = (new Slug(
        uniqueness: Uniqueness::None,
        localeUniqueness: LocaleUniqueness::AcrossLocales,
        storage: SlugStorage::LocaleMap,
        locales: TargetLocales::Supported,
        suffix: SuffixStrategy::Random,
    ))->toDefinition()->resolve(new LocalizedPage);

    expect($definition->uniqueness)->toBe(Uniqueness::None)
        ->and($definition->localeUniqueness)->toBe(LocaleUniqueness::AcrossLocales)
        ->and($definition->locales)->toBe(TargetLocales::Supported)
        ->and($definition->suffixStrategy)->toBe(SuffixStrategy::Random);

    $perLocale = (new Slug(localeUniqueness: LocaleUniqueness::PerLocale, suffixStart: 3, uniqueness: Uniqueness::Scoped, uniqueWithin: ['shop_id']))
        ->toDefinition()->resolve(new LocalizedPage);

    expect($perLocale->localeUniqueness)->toBe(LocaleUniqueness::PerLocale)
        ->and($perLocale->scopeColumns)->toBe(['shop_id'])
        ->and($perLocale->suffixGenerator->suffix('x', 1, $perLocale))->toBe('3');
});

it('cannot express a custom suffix generator', function (): void {
    (new Slug(suffix: SuffixStrategy::Custom))->toDefinition();
})->throws(InvalidSlugDefinitionException::class);

it('reads #[Slug] attributes from the model class and caches them', function (): void {
    $item = new AttributeItem;

    expect($item->slugDefinition()->scopeColumns)->toBe(['tenant_id'])
        ->and($item->getRouteKeyName())->toBe('slug')
        ->and(SlugOptionsRegistry::attributesFor(AttributeItem::class))->toHaveCount(1)
        ->and(SlugOptionsRegistry::overridesSlugOptions(AttributeItem::class))->toBeFalse();
});

it('falls back to the config defaults without attributes or override', function (): void {
    expect((new DefaultThing)->slugDefinition()->column)->toBe('slug')
        ->and(SlugOptionsRegistry::attributesFor(DefaultThing::class))->toBe([]);
});

it('inherits attributes from a parent class', function (): void {
    $child = new class extends AttributeItem {};

    expect(SlugOptionsRegistry::attributesFor($child::class))->toHaveCount(1);
});

it('refuses attributes plus an override in local/testing', function (): void {
    expect(SlugOptionsRegistry::overridesSlugOptions(ConflictingItem::class))->toBeTrue();

    (new ConflictingItem)->slugDefinition();
})->throws(InvalidSlugDefinitionException::class, 'override wins');

it('lets the override win silently in production', function (): void {
    app()->instance('env', 'production');

    try {
        expect((new ConflictingItem)->slugDefinition()->column)->toBe('slug');
    } finally {
        app()->instance('env', 'testing');
    }
});

it('starts #[Slug(suffix: Sequential)] at the configured suffix_start unless suffixStart is given', function (): void {
    config(['sluggable.defaults.suffix_start' => 1]);

    $first = function (Slug $attribute): string {
        $resolved = $attribute->toDefinition()->resolve(new Article);

        return $resolved->suffixGenerator->suffix('x', 1, $resolved);
    };

    expect($first(new Slug(suffix: SuffixStrategy::Sequential)))->toBe('1')
        ->and($first(new Slug(suffix: SuffixStrategy::Sequential, suffixStart: 5)))->toBe('5')
        ->and($first(new Slug(suffixStart: 5)))->toBe('5')
        ->and($first(new Slug))->toBe('1');
});
