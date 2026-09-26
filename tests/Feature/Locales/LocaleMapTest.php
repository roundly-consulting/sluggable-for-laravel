<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Sluggable\Contracts\SlugLocales;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Enums\TargetLocales;
use RoundlyConsulting\Sluggable\Enums\UpdatePolicy;
use RoundlyConsulting\Sluggable\Exceptions\InvalidLocaleException;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\Tests\Fixtures\AccessorPage;
use RoundlyConsulting\Sluggable\Tests\Fixtures\ContractPage;
use RoundlyConsulting\Sluggable\Tests\Fixtures\LocalizedPage;
use RoundlyConsulting\Sluggable\Tests\Fixtures\MapNamedArticle;
use RoundlyConsulting\Sluggable\Tests\Fixtures\NoContractPage;

it('generates one slug per source locale, transliterating in each locale', function (): void {
    $page = LocalizedPage::query()->create(['name' => ['en' => 'Street', 'de' => 'Straße', 'sk' => 'Ulica']]);

    expect($page->slug)->toEqual(['en' => 'street', 'de' => 'strasse', 'sk' => 'ulica']);
});

it('keeps locale uniqueness per locale', function (): void {
    LocalizedPage::query()->create(['name' => ['en' => 'Chair', 'sk' => 'Stolička']]);
    $second = LocalizedPage::query()->create(['name' => ['en' => 'Chair', 'sk' => 'Kreslo']]);

    expect($second->slug)->toEqual(['en' => 'chair-2', 'sk' => 'kreslo']);
});

it('makes slugs unique across locales on request', function (): void {
    definitionFor(LocalizedPage::class, SlugDefinition::for('slug')->from('name')->uniqueAcrossLocales());

    LocalizedPage::query()->create(['name' => ['sk' => 'Taxi']]);
    $page = LocalizedPage::query()->create(['name' => ['en' => 'Taxi', 'de' => 'Taxi']]);

    expect($page->slug['en'])->toBe('taxi-2')
        ->and($page->slug['de'])->toBe('taxi-3');
});

it('generates supported locales, resolving missing sources through the fallback', function (): void {
    definitionFor(LocalizedPage::class, SlugDefinition::for('slug')->from('name')->locales(TargetLocales::Supported));

    $page = LocalizedPage::query()->create(['name' => ['en' => 'Only English']]);

    expect($page->slug)->toEqual(['en' => 'only-english', 'sk' => 'only-english', 'de' => 'only-english']);
});

it('generates only the current locale on request', function (): void {
    definitionFor(LocalizedPage::class, SlugDefinition::for('slug')->from('name')->locales(TargetLocales::Current));
    app()->setLocale('sk');

    expect(LocalizedPage::query()->create(['name' => ['en' => 'Hello', 'sk' => 'Ahoj']])->slug)->toEqual(['sk' => 'ahoj']);
});

it('generates an explicit list or closure of locales', function (): void {
    definitionFor(LocalizedPage::class, SlugDefinition::for('slug')->from('name')->locales(['de']));
    expect(LocalizedPage::query()->create(['name' => ['en' => 'List']])->slug)->toEqual(['de' => 'list']);

    definitionFor(LocalizedPage::class, SlugDefinition::for('slug')->from('name')->locales(fn (LocalizedPage $page): array => ['sk']));
    expect(LocalizedPage::query()->create(['name' => ['en' => 'Closure']])->slug)->toEqual(['sk' => 'closure']);
});

it('rejects a hostile target locale', function (): void {
    definitionFor(LocalizedPage::class, SlugDefinition::for('slug')->from('name')->locales(["en'--"]));

    LocalizedPage::query()->create(['name' => ['en' => 'x']]);
})->throws(InvalidLocaleException::class);

it('feeds a scalar source to every locale', function (): void {
    definitionFor(LocalizedPage::class, SlugDefinition::for('slug')->from(fn (LocalizedPage $page, ?string $locale): string => "Page {$locale}")->locales(['en', 'sk']));

    expect(LocalizedPage::query()->create([])->slug)->toEqual(['en' => 'page-en', 'sk' => 'page-sk']);
});

it('drops blank and JSON-null values and preserves untouched locales byte for byte', function (): void {
    $page = LocalizedPage::query()->create(['name' => ['en' => 'Kept']]);
    DB::table('localized_pages')->where('id', $page->id)->update(['slug' => json_encode(['en' => 'kept', 'sk' => null, 'de' => ' ', 'fr' => 'Hand Made'])]);

    $page = LocalizedPage::query()->findOrFail($page->id);
    $page->update(['name' => ['en' => 'Kept', 'sk' => 'Nové']]);

    expect($page->slug)->toEqual(['en' => 'kept', 'fr' => 'Hand Made', 'sk' => 'nove']);
});

it('fills missing locales on update and leaves existing ones (IfEmpty)', function (): void {
    $page = LocalizedPage::query()->create(['name' => ['en' => 'First']]);
    $page->update(['name' => ['en' => 'Renamed', 'sk' => 'Prvý']]);

    expect($page->slug)->toEqual(['en' => 'first', 'sk' => 'prvy']);
});

it('regenerates only the locales whose source changed (WhenSourceChanges)', function (): void {
    definitionFor(LocalizedPage::class, SlugDefinition::for('slug')->from('name')->onUpdate(UpdatePolicy::WhenSourceChanges));

    $page = LocalizedPage::query()->create(['name' => ['en' => 'Alpha', 'sk' => 'Alfa']]);
    $page->update(['name' => ['en' => 'Beta', 'sk' => 'Alfa']]);

    expect($page->slug)->toEqual(['en' => 'beta', 'sk' => 'alfa']);
});

it('normalises a manual per-locale slug and leaves the others alone', function (): void {
    $page = LocalizedPage::query()->create(['name' => ['en' => 'Auto', 'sk' => 'Auto SK']]);
    $page->update(['slug' => ['en' => 'Hand Picked', 'sk' => 'auto-sk']]);

    expect($page->slug)->toEqual(['en' => 'hand-picked', 'sk' => 'auto-sk']);
});

it('reads raw maps on a model whose accessor returns the current-locale string', function (): void {
    app()->setLocale('en');

    $page = AccessorPage::query()->create(['name' => ['en' => 'Chair', 'sk' => 'Stolička']]);

    expect($page->getAttributes()['slug'])->toBeJson()
        ->and(json_decode((string) $page->getAttributes()['slug'], true))->toEqual(['en' => 'chair', 'sk' => 'stolicka'])
        ->and($page->slugFor('sk'))->toBe('stolicka')
        ->and($page->slug)->toBe('chair');
});

it('works through the ProvidesLocaleMaps contract without casts', function (): void {
    ContractPage::$writes = [];
    app()->setLocale('en');

    $page = ContractPage::query()->create(['name' => ['en' => 'Investing', 'sk' => 'Investovanie']]);

    expect($page->slugMap())->toEqual(['en' => 'investing', 'sk' => 'investovanie'])
        ->and(ContractPage::$writes)->toContain(['slug', ['en' => 'investing', 'sk' => 'investovanie']]);

    $page->update(['name' => ['en' => 'Investing', 'sk' => 'Investovanie', 'de' => 'Investieren']]);

    expect($page->fresh()?->slugMap())->toEqual(['en' => 'investing', 'sk' => 'investovanie', 'de' => 'investieren']);
});

it('refuses a model exposing locale maps without the contract', function (): void {
    NoContractPage::query()->create(['name' => json_encode(['en' => 'x'])]);
})->throws(InvalidSlugDefinitionException::class, 'ProvidesLocaleMaps');

it('builds a string slug from a locale-map source (advertisements Placement style)', function (): void {
    definitionFor(MapNamedArticle::class, SlugDefinition::for('slug')->from('name')->sourceLocale('sk'));

    expect(MapNamedArticle::query()->create(['name' => ['en' => 'Sidebar', 'sk' => 'Bočný panel']])->slug)->toBe('bocny-panel');
});

it('picks the fallback, then the current, then the first source locale for a string slug', function (): void {
    definitionFor(MapNamedArticle::class, SlugDefinition::for('slug')->from('name')->fallbackLocale('de'));

    expect(MapNamedArticle::query()->create(['name' => ['en' => 'English', 'de' => 'Deutsch']])->slug)->toBe('deutsch')
        ->and(MapNamedArticle::query()->create(['name' => ['en' => 'English', 'sk' => 'Slovensky']])->slug)->toBe('english')
        ->and(MapNamedArticle::query()->create(['name' => ['sk' => 'Slovensky', 'fr' => 'Francais']])->slug)->toBe('francais');
});

it('follows a rebound locale source', function (): void {
    app()->instance(SlugLocales::class, new class implements SlugLocales
    {
        public function supported(): array
        {
            return ['fr'];
        }

        public function fallback(): ?string
        {
            return 'fr';
        }

        public function current(): string
        {
            return 'fr';
        }
    });

    definitionFor(LocalizedPage::class, SlugDefinition::for('slug')->from('name')->locales(TargetLocales::Supported));

    expect(LocalizedPage::query()->create(['name' => ['fr' => 'Bonjour']])->slug)->toEqual(['fr' => 'bonjour']);
});

it('generates from the current locale for scalar-only sources and an empty map', function (): void {
    expect(LocalizedPage::query()->create(['name' => []])->slug)
        ->toHaveKey('en')
        ->and(LocalizedPage::query()->create(['name' => []])->slug['en'])->toMatch('/^[a-z0-9]{8}$/');
});

it('regenerates a single locale on demand', function (): void {
    $page = LocalizedPage::query()->create(['name' => ['en' => 'One', 'sk' => 'Jeden']]);
    $page->name = ['en' => 'Two', 'sk' => 'Dva'];
    $page->regenerateSlugs(locales: ['sk'])->save();

    expect($page->fresh()?->slug)->toEqual(['en' => 'one', 'sk' => 'dva']);
});
