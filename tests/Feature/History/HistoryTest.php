<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Enums\UpdatePolicy;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\Exceptions\SlugMovedException;
use RoundlyConsulting\Sluggable\Facades\Slugs;
use RoundlyConsulting\Sluggable\Models\SlugHistory;
use RoundlyConsulting\Sluggable\Tests\Fixtures\Article;
use RoundlyConsulting\Sluggable\Tests\Fixtures\Clinic;
use RoundlyConsulting\Sluggable\Tests\Fixtures\ClinicProduct;
use RoundlyConsulting\Sluggable\Tests\Fixtures\LocalizedPage;
use RoundlyConsulting\Sluggable\Tests\Fixtures\PlainThing;
use RoundlyConsulting\Sluggable\Tests\Fixtures\ScopedArticle;

beforeEach(function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->routeKey()->regenerateOnUpdate()->keepHistory());
    definitionFor(ScopedArticle::class, SlugDefinition::for('slug')->from('name')->routeKey()->regenerateOnUpdate()->keepHistory());
    definitionFor(LocalizedPage::class, SlugDefinition::for('slug')->from('name')->routeKey()->regenerateOnUpdate()->keepHistory());

    Route::middleware(SubstituteBindings::class)->group(function (): void {
        Route::get('/articles/{article}', fn (Article $article) => 'article '.$article->slug)->name('articles.show');
        Route::post('/articles/{article}', fn (Article $article) => 'posted');
        Route::get('/published/{article}', fn (ScopedArticle $article) => 'published '.$article->slug);
        Route::get('/pages/{page}', fn (LocalizedPage $page) => 'page');
        Route::get('/same/{article}/{other}', fn (Article $article, Article $other) => 'pair');
        Route::get('/clinics/{clinic}/products/{product:slug}', fn (Clinic $clinic, ClinicProduct $product) => 'product')->scopeBindings();
        Route::bind('explicit', fn (string $value) => (new Article)->resolveRouteBinding($value));
        Route::get('/explicit/{explicit}', fn () => 'explicit');
    });
});

it('records the retired slug and redirects to the current one with 301, keeping the query string', function (): void {
    $article = Article::query()->create(['name' => 'Old Name']);
    $article->update(['name' => 'New Name']);

    expect(SlugHistory::query()->where('slug', 'old-name')->exists())->toBeTrue();

    $this->get('/articles/old-name?ref=mail')->assertStatus(301)->assertRedirect('/articles/new-name?ref=mail');
    $this->get('/articles/new-name')->assertOk()->assertSee('article new-name');
});

it('redirects HEAD but never POST', function (): void {
    $article = Article::query()->create(['name' => 'Old']);
    $article->update(['name' => 'Fresh']);

    $this->call('HEAD', '/articles/old')->assertStatus(301);
    $this->post('/articles/old')->assertNotFound();
});

it('redirects a chain of renames to the latest slug', function (): void {
    $article = Article::query()->create(['name' => 'A']);
    $article->update(['name' => 'B']);
    $article->update(['name' => 'C']);

    $this->get('/articles/a')->assertRedirect('/articles/c');
    $this->get('/articles/b')->assertRedirect('/articles/c');
});

it('reclaims a slug a model takes back', function (): void {
    $article = Article::query()->create(['name' => 'Ping']);
    $article->update(['name' => 'Pong']);
    $article->update(['name' => 'Ping']);

    expect(SlugHistory::query()->where('slug', 'ping')->exists())->toBeFalse()
        ->and(SlugHistory::query()->where('slug', 'pong')->exists())->toBeTrue();

    $article->update(['name' => 'Pong']);

    expect(SlugHistory::query()->where('slug', 'pong')->exists())->toBeFalse()
        ->and(SlugHistory::query()->where('slug', 'ping')->count())->toBe(1);
});

it('never redirects to a target the route query cannot see', function (): void {
    $draft = ScopedArticle::query()->create(['name' => 'Secret', 'published' => false]);
    $draft->update(['name' => 'Secret Renamed']);

    $this->get('/published/secret')->assertNotFound();
});

it('does not redirect to a trashed model', function (): void {
    $article = Article::query()->create(['name' => 'Before']);
    $article->update(['name' => 'After']);
    $article->delete();

    $this->get('/articles/before')->assertNotFound();
});

it('redirects locale-map slugs', function (): void {
    $page = LocalizedPage::query()->create(['name' => ['en' => 'Old Page', 'sk' => 'Stará']]);
    $page->update(['name' => ['en' => 'New Page', 'sk' => 'Stará']]);

    expect(SlugHistory::query()->where('slug', 'old-page')->value('locale'))->toBe('en');

    $this->get('/pages/old-page')->assertRedirect('/pages/new-page');
});

it('redirects scoped child bindings', function (): void {
    $clinic = Clinic::query()->create(['name' => 'Vienna']);
    $product = ClinicProduct::query()->create(['clinic_id' => $clinic->id, 'name' => 'Old Product']);
    $product->update(['name' => 'New Product']);

    $this->get('/clinics/vienna/products/old-product')->assertRedirect('/clinics/vienna/products/new-product');
});

it('replaces only the moved parameter when parent and child share the raw slug', function (): void {
    $clinic = Clinic::query()->create(['name' => 'Widget']);
    $product = ClinicProduct::query()->create(['clinic_id' => $clinic->id, 'name' => 'Widget']);
    $product->update(['name' => 'Gadget']);

    $this->get('/clinics/widget/products/widget')->assertRedirect('/clinics/widget/products/gadget');
});

it('locates a parameter bound with Route::model()', function (): void {
    Route::model('modelBound', Article::class);
    Route::middleware(SubstituteBindings::class)->get('/model-bound/{modelBound}', fn () => 'ok');

    $article = Article::query()->create(['name' => 'Model Old']);
    $article->update(['name' => 'Model New']);

    $this->get('/model-bound/model-old')->assertRedirect('/model-bound/model-new');
});

it('never guesses the parameter of a custom Route::bind() closure', function (): void {
    $article = Article::query()->create(['name' => 'Explicit Old']);
    $article->update(['name' => 'Explicit New']);

    $this->get('/explicit/explicit-old')->assertNotFound();
});

it('renders a 404 when the moved parameter cannot be located', function (): void {
    $article = Article::query()->create(['name' => 'X']);
    $response = (new SlugMovedException($article, 'nowhere', 'x'))->render(request());

    expect($response->getStatusCode())->toBe(404);
});

it('is never reported', function (): void {
    $article = Article::query()->create(['name' => 'X']);

    expect(app(ExceptionHandler::class)->shouldReport(new SlugMovedException($article, 'a', 'x')))->toBeFalse();
});

it('uses the configured redirect status', function (): void {
    config(['sluggable.history.redirect_status' => 308]);
    $article = Article::query()->create(['name' => 'Old']);
    $article->update(['name' => 'New']);

    $this->get('/articles/old')->assertStatus(308);

    config(['sluggable.history.redirect_status' => 303]);
    $this->withoutExceptionHandling();

    expect(fn () => $this->get('/articles/old'))->toThrow(InvalidSlugDefinitionException::class);
});

it('finds retired slugs through the facade', function (): void {
    $article = Article::query()->create(['name' => 'Hist']);
    $article->update(['name' => 'Hist Two']);

    expect(Slugs::findInHistory(Article::class, 'hist')?->is($article))->toBeTrue()
        ->and(Slugs::findInHistory(Article::class, 'unknown'))->toBeNull()
        ->and(Slugs::findInHistory(LocalizedPage::class, 'x', locale: 'sk'))->toBeNull();
});

it('avoids reusing another model\'s retired slug when asked', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->regenerateOnUpdate()->keepHistory()->avoidHistoricalSlugs());

    $first = Article::query()->create(['name' => 'Classic']);
    $first->update(['name' => 'Modern']);

    expect(Article::query()->create(['name' => 'Classic'])->slug)->toBe('classic-2');

    // Its OWN retired slug is not off limits: the first model reclaims it.
    $first->update(['name' => 'Classic']);
    expect($first->slug)->toBe('classic');
});

it('keeps history on soft delete and purges it on force delete', function (): void {
    $article = Article::query()->create(['name' => 'Kept']);
    $article->update(['name' => 'Kept Two']);
    $article->delete();

    expect(SlugHistory::query()->count())->toBe(1);

    $article->forceDelete();

    expect(SlugHistory::withTrashed()->count())->toBe(0);
});

it('purges history when a model without soft deletes is deleted', function (): void {
    definitionFor(PlainThing::class, SlugDefinition::for('slug')->from('name')->onUpdate(UpdatePolicy::Always)->keepHistory());

    $thing = PlainThing::query()->create(['name' => 'One']);
    $thing->update(['name' => 'Two']);
    expect(SlugHistory::query()->count())->toBe(1);

    $thing->delete();
    expect(SlugHistory::query()->count())->toBe(0);
});

it('records history for every definition when enabled globally', function (): void {
    config(['sluggable.history.enabled' => true]);
    definitionFor(PlainThing::class, SlugDefinition::for('slug')->from('name')->onUpdate(UpdatePolicy::Always));

    $thing = PlainThing::query()->create(['name' => 'Global']);
    $thing->update(['name' => 'Globally']);

    expect(SlugHistory::query()->where('slug', 'global')->exists())->toBeTrue();
});

it('prunes entries older than the configured age, and nothing when unset', function (): void {
    $old = SlugHistory::factory()->create(['created_at' => Carbon::now()->subDays(40)]);
    $new = SlugHistory::factory()->create(['sluggable_type' => Article::class]);

    Artisan::call('model:prune', ['--model' => [SlugHistory::class]]);
    expect(SlugHistory::query()->count())->toBe(2);

    config(['sluggable.history.prune_after_days' => 30]);
    Artisan::call('model:prune', ['--model' => [SlugHistory::class]]);

    expect(SlugHistory::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(SlugHistory::query()->whereKey($new->id)->exists())->toBeTrue()
        ->and($new->sluggable())->toBeInstanceOf(MorphTo::class);
});

it('fails loudly in testing when the history table is missing', function (): void {
    Schema::drop('slug_history');

    $article = Article::query()->create(['name' => 'No Table']);

    expect(fn () => $article->update(['name' => 'Still No Table']))->toThrow(InvalidSlugDefinitionException::class, 'does not exist')
        ->and(Slugs::findInHistory(Article::class, 'x'))->toBeNull();
});

it('logs and skips when the history table is missing in production', function (): void {
    Schema::drop('slug_history');
    Log::spy();
    app()->instance('env', 'production');

    try {
        $article = Article::query()->create(['name' => 'Prod']);
        $article->update(['name' => 'Prod Two']);
    } finally {
        app()->instance('env', 'testing');
    }

    expect($article->fresh()?->slug)->toBe('prod-two');
    Log::shouldHaveReceived('warning')->once();
});
