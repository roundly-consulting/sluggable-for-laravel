<?php

declare(strict_types=1);

use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Definitions\SlugOptions;
use RoundlyConsulting\Sluggable\Enums\EmptySourcePolicy;
use RoundlyConsulting\Sluggable\Enums\LocaleFallback;
use RoundlyConsulting\Sluggable\Enums\LocaleUniqueness;
use RoundlyConsulting\Sluggable\Enums\ManualSlugPolicy;
use RoundlyConsulting\Sluggable\Enums\SlugStorage;
use RoundlyConsulting\Sluggable\Enums\SuffixStrategy;
use RoundlyConsulting\Sluggable\Enums\TargetLocales;
use RoundlyConsulting\Sluggable\Enums\Uniqueness;
use RoundlyConsulting\Sluggable\Enums\UpdatePolicy;
use RoundlyConsulting\Sluggable\Exceptions\InvalidLocaleException;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\Suffixes\ClosureSuffix;
use RoundlyConsulting\Sluggable\Suffixes\RandomSuffix;
use RoundlyConsulting\Sluggable\Suffixes\SequentialSuffix;
use RoundlyConsulting\Sluggable\Tests\Fixtures\Article;
use RoundlyConsulting\Sluggable\Tests\Fixtures\LocalizedPage;
use RoundlyConsulting\Sluggable\Tests\Fixtures\NoContractPage;
use RoundlyConsulting\Sluggable\Tests\Fixtures\UuidThing;

it('fills every unset option from config', function (): void {
    $definition = SlugDefinition::for('slug')->resolve(new Article);

    expect($definition->column)->toBe('slug')
        ->and($definition->storage)->toBe(SlugStorage::String)
        ->and($definition->sources[0]->source)->toBe('name')
        ->and($definition->format->separator)->toBe('-')
        ->and($definition->format->maxLength)->toBe(255)
        ->and($definition->format->maxWords)->toBeNull()
        ->and($definition->format->dictionary)->toBe(['@' => 'at'])
        ->and($definition->language)->toBe('en')
        ->and($definition->uniqueness)->toBe(Uniqueness::Global)
        ->and($definition->includeTrashed)->toBeTrue()
        ->and($definition->onCreate)->toBeTrue()
        ->and($definition->onUpdate)->toBe(UpdatePolicy::IfEmpty)
        ->and($definition->manual)->toBe(ManualSlugPolicy::Normalize)
        ->and($definition->emptySource)->toBe(EmptySourcePolicy::Random)
        ->and($definition->suffixStrategy)->toBe(SuffixStrategy::Sequential)
        ->and($definition->suffixGenerator)->toBeInstanceOf(SequentialSuffix::class)
        ->and($definition->randomLength)->toBe(8)
        ->and($definition->locales)->toBe(TargetLocales::Source)
        ->and($definition->fallback)->toBe(LocaleFallback::Any)
        ->and($definition->retries)->toBe(3)
        ->and($definition->keepHistory)->toBeFalse()
        ->and($definition->redirectFromHistory)->toBeFalse();
});

it('honours every fluent option', function (): void {
    $definition = SlugDefinition::for('handle')
        ->from(['brand.name', 'name'])
        ->separator('_')
        ->maxLength(40)
        ->maxWords(3)
        ->language('de')
        ->dictionary(['&' => 'and'])
        ->unicode()
        ->lowercase(false)
        ->reserved(['Admin'])
        ->prefix('p')
        ->suffix('s')
        ->uniqueWithin('tenant_id')
        ->excludeTrashed()
        ->randomSuffix(12)
        ->onCreate(false)
        ->regenerateOnUpdate()
        ->manual(ManualSlugPolicy::Strict)
        ->whenEmptySource(EmptySourcePolicy::Fail)
        ->locked()
        ->fallback(LocaleFallback::Fallback)
        ->fallbackLocale('sk')
        ->routeKey()
        ->bindByKeyFallback()
        ->keepHistory()
        ->redirectFromHistory(false)
        ->avoidHistoricalSlugs()
        ->retries(5)
        ->resolve(new Article);

    expect($definition->sources[0]->isRelationPath)->toBeTrue()
        ->and($definition->sources[1]->isAttribute())->toBeTrue()
        ->and($definition->format->separator)->toBe('_')
        ->and($definition->format->maxLength)->toBe(40)
        ->and($definition->format->maxWords)->toBe(3)
        ->and($definition->format->unicode)->toBeTrue()
        ->and($definition->format->lowercase)->toBeFalse()
        ->and($definition->format->reserved)->toContain('admin')
        ->and($definition->languageFor(null))->toBe('de')
        ->and($definition->scopeColumns)->toBe(['tenant_id'])
        ->and($definition->includeTrashed)->toBeFalse()
        ->and($definition->suffixGenerator)->toBeInstanceOf(RandomSuffix::class)
        ->and($definition->randomLength)->toBe(12)
        ->and($definition->onCreate)->toBeFalse()
        ->and($definition->onUpdate)->toBe(UpdatePolicy::WhenSourceChanges)
        ->and($definition->locked)->toBeTrue()
        ->and($definition->routeKey)->toBeTrue()
        ->and($definition->bindByKeyFallback)->toBeTrue()
        ->and($definition->keepHistory)->toBeTrue()
        ->and($definition->redirectFromHistory)->toBeFalse()
        ->and($definition->avoidHistoricalSlugs)->toBeTrue()
        ->and($definition->retries)->toBe(5);
});

it('detects locale maps by cast and maps language per locale', function (): void {
    $definition = SlugDefinition::for('slug')->from('name')->uniqueAcrossLocales()->locales(['en', 'sk'])->resolve(new LocalizedPage);

    expect($definition->isLocalized())->toBeTrue()
        ->and($definition->localeUniqueness)->toBe(LocaleUniqueness::AcrossLocales)
        ->and($definition->languageFor('pt-BR'))->toBe('pt_BR')
        ->and($definition->locales)->toBe(['en', 'sk']);
});

it('resolves a closure language and falls back to the locale', function (): void {
    $definition = SlugDefinition::for('slug')->language(fn (?string $locale): ?string => $locale === 'sk' ? 'cs' : null)->resolve(new LocalizedPage);

    expect($definition->languageFor('sk'))->toBe('cs')
        ->and($definition->languageFor('de'))->toBe('de');
});

it('can force a storage', function (): void {
    expect(SlugDefinition::for('slug')->localized()->resolve(new Article)->storage)->toBe(SlugStorage::LocaleMap)
        ->and(SlugDefinition::for('slug')->localized(false)->resolve(new LocalizedPage)->storage)->toBe(SlugStorage::String)
        ->and(SlugDefinition::for('slug')->storage(SlugStorage::String)->resolve(new LocalizedPage)->storage)->toBe(SlugStorage::String);
});

it('builds a closure suffix generator', function (): void {
    $definition = SlugDefinition::for('slug')->suffixUsing(fn (string $base, int $attempt): string => 'v'.$attempt)->resolve(new Article);

    expect($definition->suffixGenerator)->toBeInstanceOf(ClosureSuffix::class)
        ->and($definition->suffixStrategy)->toBe(SuffixStrategy::Custom);
});

it('rejects invalid definitions', function (Closure $definition, string $message): void {
    expect(fn () => $definition()->resolve(new Article))->toThrow(InvalidSlugDefinitionException::class, $message);
})->with([
    'separator' => [fn () => SlugDefinition::for('slug')->separator('/'), 'separator'],
    'long separator' => [fn () => SlugDefinition::for('slug')->separator('----'), 'separator'],
    'separator with a trailing newline' => [fn () => SlugDefinition::for('slug')->separator("-\n"), 'separator'],
    'column with a trailing newline' => [fn () => SlugDefinition::for("slug\n"), 'not a valid SQL identifier'],
    'column' => [fn () => SlugDefinition::for('slug; drop'), 'not a valid SQL identifier'],
    'self source' => [fn () => SlugDefinition::for('slug')->from('slug'), 'itself'],
    'source identifier' => [fn () => SlugDefinition::for('slug')->from('na me'), 'not a valid SQL identifier'],
    'scope identifier' => [fn () => SlugDefinition::for('slug')->uniqueWithin('x y'), 'not a valid SQL identifier'],
    'scoped without scope' => [fn () => SlugDefinition::for('slug')->uniqueWithin(), 'scoped'],
    'max length' => [fn () => SlugDefinition::for('slug')->maxLength(7), 'maxLength'],
    'affix too long' => [fn () => SlugDefinition::for('slug')->maxLength(10)->prefix('abcdefghij'), 'maxLength'],
    'random length' => [fn () => SlugDefinition::for('slug')->randomLength(3), 'randomLength'],
    'retries' => [fn () => SlugDefinition::for('slug')->retries(21), 'retries'],
    'suffix start' => [fn () => SlugDefinition::for('slug')->sequentialSuffix(0), 'sequentialSuffix'],
    'max words' => [fn () => SlugDefinition::for('slug')->maxWords(0), 'maxWords'],
    'across on string' => [fn () => SlugDefinition::for('slug')->uniqueAcrossLocales(), 'uniqueAcrossLocales'],
    'locales on string' => [fn () => SlugDefinition::for('slug')->locales(['en']), 'locales'],
]);

it('rejects an invalid source locale', function (): void {
    SlugDefinition::for('slug')->sourceLocale('EN!')->resolve(new Article);
})->throws(InvalidLocaleException::class);

it('refuses an encrypted slug column', function (): void {
    $model = new class extends Article
    {
        protected $casts = ['slug' => 'encrypted'];
    };

    SlugDefinition::for('slug')->resolve($model);
})->throws(InvalidSlugDefinitionException::class, 'cannot be queried');

it('refuses a locale map without the contract', function (): void {
    SlugDefinition::for('slug')->resolve(new NoContractPage);
})->throws(InvalidSlugDefinitionException::class, 'ProvidesLocaleMaps');

it('refuses history when the model key does not fit the history key type', function (): void {
    SlugDefinition::for('slug')->keepHistory()->resolve(new UuidThing);
})->throws(InvalidSlugDefinitionException::class, 'key_type');

it('refuses history for slugs longer than the history column holds', function (): void {
    expect(SlugDefinition::for('slug')->maxLength(255)->keepHistory()->resolve(new Article)->keepHistory)->toBeTrue()
        ->and(SlugDefinition::for('slug')->maxLength(500)->resolve(new Article)->format->maxLength)->toBe(500);

    SlugDefinition::for('slug')->maxLength(256)->keepHistory()->resolve(new Article);
})->throws(InvalidSlugDefinitionException::class, 'history');

it('accepts history for uuid models when the key type is uuid', function (): void {
    config(['sluggable.key_type' => 'uuid']);

    expect(SlugDefinition::for('slug')->keepHistory()->resolve(new UuidThing)->keepHistory)->toBeTrue();
});

it('validates the option set', function (): void {
    expect(fn () => SlugOptions::make()->resolve(new Article))->toThrow(InvalidSlugDefinitionException::class)
        ->and(fn () => SlugOptions::make(SlugDefinition::for('slug'), SlugDefinition::for('slug'))->resolve(new Article))
        ->toThrow(InvalidSlugDefinitionException::class, 'more than once')
        ->and(fn () => SlugOptions::make(SlugDefinition::for('slug')->routeKey(), SlugDefinition::for('code')->routeKey())->resolve(new Article))
        ->toThrow(InvalidSlugDefinitionException::class, 'routeKey');
});

it('exposes the definitions of an option set', function (): void {
    $options = SlugOptions::make(SlugDefinition::for('slug'))->add(SlugDefinition::for('code'));

    expect($options->columns())->toBe(['slug', 'code'])
        ->and($options->get('code')->column())->toBe('code')
        ->and($options->definitions())->toHaveCount(2)
        ->and(fn () => $options->get('missing'))->toThrow(InvalidSlugDefinitionException::class);

    $resolved = $options->resolve(new Article);

    expect($resolved->columns())->toBe(['slug', 'code'])
        ->and($resolved->default()->column)->toBe('slug')
        ->and($resolved->routeKey())->toBeNull()
        ->and($resolved->has('code'))->toBeTrue()
        ->and($resolved->get()->column)->toBe('slug')
        ->and(fn () => $resolved->get('nope'))->toThrow(InvalidSlugDefinitionException::class);
});

it('builds the default option set from config', function (): void {
    config(['sluggable.defaults.column' => 'handle', 'sluggable.defaults.source' => 'title']);

    $definition = SlugOptions::defaults()->resolve(new Article)->default();

    expect($definition->column)->toBe('handle')
        ->and($definition->sources[0]->source)->toBe('title');
});

it('matches values a suffix strategy could have produced', function (): void {
    $sequential = SlugDefinition::for('slug')->resolve(new Article);
    $random = SlugDefinition::for('slug')->randomSuffix(6)->resolve(new Article);
    $custom = SlugDefinition::for('slug')->suffixUsing(fn (): string => 'x')->resolve(new Article);

    expect(preg_match($sequential->suffixPattern('chair'), 'chair-12'))->toBe(1)
        ->and(preg_match($sequential->suffixPattern('chair'), 'chair-x'))->toBe(0)
        ->and(preg_match($random->suffixPattern('chair'), 'chair-ab12cd'))->toBe(1)
        ->and(preg_match($custom->suffixPattern('chair'), 'chair-anything'))->toBe(1)
        ->and(preg_match($custom->suffixPattern('chair'), 'table'))->toBe(0);
});

it('starts sequentialSuffix() at the configured suffix_start unless a start is given', function (): void {
    config(['sluggable.defaults.suffix_start' => 1]);

    $first = function (SlugDefinition $definition): string {
        $resolved = $definition->resolve(new Article);

        return $resolved->suffixGenerator->suffix('x', 1, $resolved);
    };

    expect($first(SlugDefinition::for('slug')))->toBe('1')
        ->and($first(SlugDefinition::for('slug')->sequentialSuffix()))->toBe('1')
        ->and($first(SlugDefinition::for('slug')->sequentialSuffix(5)))->toBe('5')
        ->and($first(SlugDefinition::for('slug')->sequentialSuffix(start: 5)->sequentialSuffix()))->toBe('1');
});
