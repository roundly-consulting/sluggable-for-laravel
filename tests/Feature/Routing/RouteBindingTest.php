<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Tests\Fixtures\Article;
use RoundlyConsulting\Sluggable\Tests\Fixtures\Clinic;
use RoundlyConsulting\Sluggable\Tests\Fixtures\ClinicCategory;
use RoundlyConsulting\Sluggable\Tests\Fixtures\ClinicProduct;
use RoundlyConsulting\Sluggable\Tests\Fixtures\LocalizedPage;
use RoundlyConsulting\Sluggable\Tests\Fixtures\MultiSlugThing;
use RoundlyConsulting\Sluggable\Tests\Fixtures\UuidThing;

beforeEach(function (): void {
    Route::middleware(SubstituteBindings::class)->group(function (): void {
        Route::get('/pages/{page}', fn (LocalizedPage $page) => $page->getKey())->name('pages.show');
        Route::get('/archive/{page}', fn (LocalizedPage $page) => $page->getKey())->withTrashed();
        Route::get('/things/{thing:handle}', fn (MultiSlugThing $thing) => $thing->getKey());
        Route::get('/uuid/{thing}', fn (UuidThing $thing) => $thing->getKey());
        Route::get('/uuid-id/{thing:id}', fn (UuidThing $thing) => $thing->getKey());
        Route::get('/articles/{article}', fn (Article $article) => $article->getKey());
        Route::get('/clinics/{clinic}/products/{product:slug}', fn (Clinic $clinic, ClinicProduct $product) => $product->getKey())->scopeBindings();
        Route::get('/clinics/{clinic}/categories/{clinicCategory:slug}', fn (Clinic $clinic, ClinicCategory $clinicCategory) => $clinicCategory->getKey())->scopeBindings();
    });
});

it('binds the routeKey column through the locale chain', function (): void {
    $page = LocalizedPage::query()->create(['name' => ['en' => 'About', 'sk' => 'O nás']]);

    $this->get('/pages/about')->assertOk()->assertSee((string) $page->id);
    $this->get('/pages/o-nas')->assertOk();
    $this->get('/pages/missing')->assertNotFound();
});

it('uses the current-locale slug as route key', function (): void {
    $page = LocalizedPage::query()->create(['name' => ['en' => 'About', 'sk' => 'O nás']]);

    expect($page->getRouteKeyName())->toBe('slug')
        ->and(route('pages.show', $page))->toEndWith('/pages/about');

    app()->setLocale('sk');
    expect(route('pages.show', $page))->toEndWith('/pages/o-nas');
});

it('falls back to the parent route key without a routeKey() definition', function (): void {
    $article = Article::query()->create(['name' => 'By Id']);

    expect($article->getRouteKeyName())->toBe('id')
        ->and($article->getRouteKey())->toBe($article->id);

    $this->get('/articles/'.$article->id)->assertOk();
});

it('binds any slug column by field', function (): void {
    $thing = MultiSlugThing::query()->create(['name' => 'Field Bound']);

    $this->get('/things/field_bound')->assertOk()->assertSee((string) $thing->id);
});

it('binds trashed models on withTrashed() routes only', function (): void {
    $page = LocalizedPage::query()->create(['name' => ['en' => 'Deleted']]);
    $page->delete();

    $this->get('/pages/deleted')->assertNotFound();
    $this->get('/archive/deleted')->assertOk();
});

it('scopes child bindings to the parent', function (): void {
    $vienna = Clinic::query()->create(['name' => 'Vienna']);
    $graz = Clinic::query()->create(['name' => 'Graz']);
    $product = ClinicProduct::query()->create(['clinic_id' => $vienna->id, 'name' => 'Vaccine']);
    ClinicProduct::query()->create(['clinic_id' => $graz->id, 'name' => 'Other']);

    $this->get('/clinics/vienna/products/vaccine')->assertOk()->assertSee((string) $product->id);
    $this->get('/clinics/graz/products/vaccine')->assertNotFound();
});

it('scopes locale-map child bindings through a BelongsToMany (qualified field)', function (): void {
    $clinic = Clinic::query()->create(['name' => 'Linz']);
    $category = ClinicCategory::query()->create(['name' => ['en' => 'Dogs', 'sk' => 'Psy']]);
    $clinic->clinicCategories()->attach($category);
    ClinicCategory::query()->create(['name' => ['en' => 'Cats']]);

    $this->get('/clinics/linz/categories/psy')->assertOk()->assertSee((string) $category->id);
    $this->get('/clinics/linz/categories/cats')->assertNotFound();
});

it('never matches a non-string, over-long or control-character value and skips the database', function (): void {
    $model = new LocalizedPage;

    expect($model->resolveRouteBindingQuery($model, ['array'])->exists())->toBeFalse();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    expect($model->resolveRouteBinding(str_repeat('a', 300)))->toBeNull()
        ->and($model->resolveRouteBinding("a\x00b"))->toBeNull()
        ->and($model->resolveSoftDeletableRouteBinding(['x']))->toBeNull()
        ->and($queries)->toBe(0);
});

it('falls back to the primary key only after a miss and only for key-shaped values', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->routeKey()->bindByKeyFallback());

    $numericSlug = Article::query()->create(['name' => '2024']);
    $byId = Article::query()->create(['name' => 'Some Title']);

    $this->get('/articles/2024')->assertOk()->assertSee((string) $numericSlug->id);
    $this->get('/articles/'.$byId->id)->assertOk()->assertSee((string) $byId->id);
    $this->get('/articles/99999999999999999999')->assertNotFound();
});

it('checks the key shape of uuid models before falling back', function (): void {
    $thing = UuidThing::query()->create(['name' => 'Uuid Named']);

    $this->get('/uuid/uuid-named')->assertOk()->assertSee($thing->id);
    $this->get('/uuid/'.$thing->id)->assertOk();
    $this->get('/uuid/not-a-uuid')->assertNotFound();
    $this->get('/uuid-id/not-a-uuid')->assertNotFound();
    $this->get('/uuid-id/'.$thing->id)->assertOk();
});

it('does not fall back to the key for other key types', function (): void {
    $model = new class extends Article
    {
        protected $keyType = 'string';
    };

    definitionFor($model::class, SlugDefinition::for('slug')->from('name')->routeKey()->bindByKeyFallback());

    expect($model->resolveRouteBinding('abc'))->toBeNull();
});
