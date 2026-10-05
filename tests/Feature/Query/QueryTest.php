<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Enums\LocaleFallback;
use RoundlyConsulting\Sluggable\Exceptions\InvalidLocaleException;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\Tests\Fixtures\Article;
use RoundlyConsulting\Sluggable\Tests\Fixtures\LocalizedPage;
use RoundlyConsulting\Sluggable\Tests\Fixtures\MultiSlugThing;
use RoundlyConsulting\Sluggable\Tests\Fixtures\ScopedArticle;

it('accepts a slug array that is not a list in whereSlugIn()', function (): void {
    $article = Article::query()->create(['name' => 'Y']);
    $page = LocalizedPage::query()->create(['name' => ['en' => 'Y']]);

    expect(Article::query()->whereSlugIn([1 => 'y'])->sole()->is($article))->toBeTrue()
        ->and(Article::query()->whereSlugIn(array_filter(['', 'y']))->sole()->is($article))->toBeTrue()
        ->and(Article::query()->whereSlugIn(['a' => 'nope', 'b' => 'y'])->sole()->is($article))->toBeTrue()
        ->and(LocalizedPage::query()->whereSlugIn([1 => 'y'])->sole()->is($page))->toBeTrue()
        ->and(LocalizedPage::query()->whereSlugIn([1 => 'y'], locale: 'en')->sole()->is($page))->toBeTrue();
});

it('finds string slugs by scope and finder', function (): void {
    $article = Article::query()->create(['name' => 'Find Me']);

    expect(Article::query()->whereSlug('find-me')->first()?->is($article))->toBeTrue()
        ->and(Article::findBySlug('find-me')?->is($article))->toBeTrue()
        ->and(Article::findBySlug('nope'))->toBeNull()
        ->and(Article::query()->whereSlugIn(['find-me', 'other'])->count())->toBe(1)
        ->and(Article::query()->whereSlugInAnyLocale('find-me')->count())->toBe(1);
});

it('throws a 404-able exception from findBySlugOrFail', function (): void {
    Article::findBySlugOrFail('missing');
})->throws(ModelNotFoundException::class);

it('queries a non-default column', function (): void {
    $thing = MultiSlugThing::query()->create(['name' => 'Multi', 'sku' => 'Z9']);

    expect(MultiSlugThing::query()->whereSlug('X-Z9', column: 'code')->first()?->is($thing))->toBeTrue()
        ->and(MultiSlugThing::findBySlug('multi', 'handle')?->is($thing))->toBeTrue();
});

it('rejects a locale on a string column', function (): void {
    Article::query()->whereSlug('x', locale: 'en')->get();
})->throws(InvalidSlugDefinitionException::class);

it('matches an exact locale, or the chain', function (): void {
    $page = LocalizedPage::query()->create(['name' => ['en' => 'Investing', 'sk' => 'Investovanie']]);

    expect(LocalizedPage::query()->whereSlug('investovanie', locale: 'sk')->first()?->is($page))->toBeTrue()
        ->and(LocalizedPage::query()->whereSlug('investovanie', locale: 'en')->exists())->toBeFalse()
        ->and(LocalizedPage::query()->whereSlug('investovanie')->exists())->toBeTrue()
        ->and(LocalizedPage::query()->whereSlugIn(['investing', 'x'])->count())->toBe(1)
        ->and(LocalizedPage::query()->whereSlugIn(['investovanie'], locale: 'sk')->count())->toBe(1)
        ->and(LocalizedPage::query()->whereSlugInAnyLocale('investovanie')->count())->toBe(1);
});

it('stops the chain at the current locale with LocaleFallback::None', function (): void {
    definitionFor(LocalizedPage::class, SlugDefinition::for('slug')->from('name')->fallback(LocaleFallback::None));
    LocalizedPage::query()->create(['name' => ['sk' => 'Len Slovensky']]);

    expect(LocalizedPage::query()->whereSlug('len-slovensky')->exists())->toBeFalse();

    app()->setLocale('sk');
    expect(LocalizedPage::query()->whereSlug('len-slovensky')->exists())->toBeTrue();
});

it('adds predicates only, so count() and paginate() work on every engine', function (): void {
    LocalizedPage::query()->create(['name' => ['en' => 'Counted']]);

    $query = LocalizedPage::query()->whereSlug('counted');

    expect(strtolower($query->toSql()))->not->toContain('order by')
        ->and($query->count())->toBe(1)
        ->and(LocalizedPage::query()->whereSlug('counted')->paginate()->total())->toBe(1);
});

it('prefers the current-locale match in finders', function (): void {
    $english = LocalizedPage::query()->create(['name' => ['en' => 'Gift']]);
    $german = LocalizedPage::query()->create(['name' => ['de' => 'Gift']]);

    app()->setLocale('de');
    expect(LocalizedPage::findBySlug('gift')?->is($german))->toBeTrue();

    app()->setLocale('en');
    expect(LocalizedPage::findBySlug('gift')?->is($english))->toBeTrue()
        ->and(LocalizedPage::query()->whereSlug('gift')->orderBySlugPreference('gift')->first()?->is($english))->toBeTrue()
        ->and(Article::query()->orderBySlugPreference('x')->toSql())->not->toContain('case');
});

it('applies the model\'s global scopes on reads', function (): void {
    ScopedArticle::query()->create(['name' => 'Draft', 'published' => false]);

    expect(ScopedArticle::findBySlug('draft'))->toBeNull();
});

it('reads slugs per locale', function (): void {
    $page = LocalizedPage::query()->create(['name' => ['sk' => 'Iba SK', 'de' => 'Nur DE']]);

    expect($page->slugMap())->toEqual(['sk' => 'iba-sk', 'de' => 'nur-de'])
        ->and($page->slugFor('de'))->toBe('nur-de')
        ->and($page->slugFor('en'))->toBeNull()
        ->and($page->currentSlug())->toBe('iba-sk')
        ->and(fn () => $page->slugFor('EN!'))->toThrow(InvalidLocaleException::class);

    app()->setLocale('de');
    expect($page->currentSlug())->toBe('nur-de');

    definitionFor(LocalizedPage::class, SlugDefinition::for('slug')->from('name')->fallback(LocaleFallback::Fallback));
    app()->setLocale('en');
    $fresh = LocalizedPage::query()->findOrFail($page->id);

    expect($fresh->currentSlug())->toBeNull()
        ->and(LocalizedPage::query()->create(['name' => []])->currentSlug())->not->toBeNull();
});

it('reads string slugs uniformly', function (): void {
    $article = Article::query()->create(['name' => 'Single']);
    $empty = new Article;

    expect($article->slugMap())->toBe([Sluggable::SINGLE_KEY => 'single'])
        ->and($empty->slugMap())->toBe([])
        ->and($article->currentSlug())->toBe('single')
        ->and(fn () => $article->slugFor('en'))->toThrow(InvalidSlugDefinitionException::class);
});

it('treats an integer slug value as a string', function (): void {
    DB::table('articles')->insert(['slug' => '2024']);

    expect(Article::findBySlug('2024')?->currentSlug())->toBe('2024');
});
