<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Sluggable\Actions\QueueSlugRegenerationAction;
use RoundlyConsulting\Sluggable\DataTransferObjects\RegenerateSlugsData;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugDuplicate;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Enums\RegenerationMode;
use RoundlyConsulting\Sluggable\Events\SlugsRegenerated;
use RoundlyConsulting\Sluggable\Exceptions\InvalidLocaleException;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\Facades\Slugs;
use RoundlyConsulting\Sluggable\Jobs\RegenerateSlugsJob;
use RoundlyConsulting\Sluggable\Models\SlugHistory;
use RoundlyConsulting\Sluggable\ModelSlugs;
use RoundlyConsulting\Sluggable\SlugManager;
use RoundlyConsulting\Sluggable\Tests\Fixtures\Article;
use RoundlyConsulting\Sluggable\Tests\Fixtures\Brand;
use RoundlyConsulting\Sluggable\Tests\Fixtures\LocalizedPage;
use RoundlyConsulting\Sluggable\Tests\Fixtures\ScopedArticle;
use RoundlyConsulting\Sluggable\Tests\Fixtures\ScopedItem;

it('scopes class-wide operations to a model class or its morph alias', function (): void {
    expect(Slugs::model(Article::class))->toBeInstanceOf(ModelSlugs::class)
        ->modelClass->toBe(Article::class);

    Relation::morphMap(['article' => Article::class]);

    try {
        expect(Slugs::model('article')->modelClass)->toBe(Article::class);
    } finally {
        Relation::morphMap([], false);
    }
});

it('refuses a class that is not a sluggable model', function (string $model): void {
    expect(fn () => Slugs::model($model))->toThrow(InvalidSlugDefinitionException::class, 'Sluggable contract');
})->with([
    'no such class' => ['Nope\\Nothing'],
    'not sluggable' => [Brand::class],
    'not a model' => [SlugManager::class],
]);

it('regenerates a whole model through the facade, stale mode, history on request', function (): void {
    Event::fake([SlugsRegenerated::class]);
    $article = Article::query()->create(['name' => 'Before']);
    DB::table('articles')->update(['name' => 'After']);

    $report = Slugs::model(Article::class)->regenerate(mode: RegenerationMode::Stale, withHistory: true);

    expect($report->scanned)->toBe(1)
        ->and($report->changed)->toBe(1)
        ->and($article->fresh()?->slug)->toBe('after')
        ->and(SlugHistory::query()->where('slug', 'before')->exists())->toBeTrue();
    Event::assertDispatched(SlugsRegenerated::class);
});

it('previews a regeneration without writing', function (): void {
    DB::table('articles')->insert(['name' => 'Dry']);

    $report = Slugs::model(Article::class)->regenerate(dryRun: true);

    expect($report->changed)->toBe(1)
        ->and($report->samples[0]->change->current)->toBe('dry')
        ->and(DB::table('articles')->value('slug'))->toBeNull();
});

it('limits a regeneration to columns and validates its locales', function (): void {
    $page = LocalizedPage::query()->create(['name' => ['en' => 'Hello', 'de' => 'Hallo']]);
    DB::table('localized_pages')->update(['slug' => json_encode(['en' => 'hello'])]);

    Slugs::model(LocalizedPage::class)->regenerate(columns: ['slug'], locales: ['de'], chunk: 1, withoutEvents: true);

    expect($page->fresh()?->slug)->toEqual(['en' => 'hello', 'de' => 'hallo'])
        ->and(fn () => Slugs::model(LocalizedPage::class)->regenerate(locales: ['EN!']))->toThrow(InvalidLocaleException::class)
        ->and(fn () => Slugs::model(Article::class)->regenerate(chunk: 0))->toThrow(InvalidArgumentException::class, 'at least 1');
});

it('forces a regeneration past locks', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->lockWhen(fn (Article $article): bool => (bool) $article->published));
    $locked = Article::query()->create(['name' => 'Locked', 'published' => true]);
    DB::table('articles')->update(['name' => 'Unlocked']);

    Slugs::model(Article::class)->regenerate(mode: RegenerationMode::Stale);
    expect($locked->fresh()?->slug)->toBe('locked');

    Slugs::model(Article::class)->regenerate(mode: RegenerationMode::Stale, force: true);
    expect($locked->fresh()?->slug)->toBe('unlocked');
});

it('queues a regeneration as one job per chunk of keys', function (): void {
    DB::table('articles')->insert([['name' => 'Q1'], ['name' => 'Q2'], ['name' => 'Q3']]);
    Bus::fake();

    $jobs = Slugs::model(Article::class)->queueRegeneration(mode: RegenerationMode::All, chunk: 2, withHistory: true);

    expect($jobs)->toBe(2);
    Bus::assertDispatchedTimes(RegenerateSlugsJob::class, 2);
    Bus::assertDispatched(RegenerateSlugsJob::class, fn (RegenerateSlugsJob $job): bool => $job->data->mode === RegenerationMode::All
        && $job->data->withHistory
        && ! $job->data->dryRun
        && $job->keys !== []);
});

it('queues through the action, honouring an explicit key list', function (): void {
    DB::table('articles')->insert([['name' => 'K1'], ['name' => 'K2'], ['name' => 'K3']]);
    $keys = DB::table('articles')->orderBy('id')->limit(2)->pluck('id')->all();
    Bus::fake();

    $jobs = app(QueueSlugRegenerationAction::class)->execute(new RegenerateSlugsData(Article::class, chunk: 10, keys: $keys));

    expect($jobs)->toBe(1);
    Bus::assertDispatched(RegenerateSlugsJob::class, fn (RegenerateSlugsJob $job): bool => $job->keys === $keys);
});

it('lists duplicates through the facade', function (): void {
    dropSlugIndex('articles', 'articles_slug_slug_unique');
    DB::table('articles')->insert([['slug' => 'dup'], ['slug' => 'dup'], ['slug' => 'single']]);

    $findings = Slugs::model(Article::class)->duplicates();

    expect($findings)->toHaveCount(1)
        ->and($findings[0])->toBeInstanceOf(SlugDuplicate::class)
        ->and($findings[0]->slug)->toBe('dup')
        ->and($findings[0]->keys)->toHaveCount(2)
        ->and(Slugs::model(Article::class)->duplicates(column: 'other'))->toBe([]);
});

it('plans and creates a model\'s indexes through the facade', function (): void {
    dropSlugIndex('scoped_items', 'scoped_items_slug_slug_unique');

    $plan = Slugs::model(ScopedItem::class)->indexes(dryRun: true);

    expect($plan->created)->toBe(['scoped_items_slug_slug_unique'])
        ->and($plan->statements)->not->toBe([])
        ->and(Slugs::model(ScopedItem::class)->indexes(columns: ['slug'])->created)->toBe(['scoped_items_slug_slug_unique'])
        ->and(Slugs::model(ScopedItem::class)->indexes()->skipped)->toBe(['scoped_items_slug_slug_unique']);
});

it('finds a retired slug only through the query it is given', function (): void {
    definitionFor(ScopedArticle::class, SlugDefinition::for('slug')->from('name')->regenerateOnUpdate()->keepHistory());
    $draft = ScopedArticle::query()->withoutGlobalScopes()->create(['name' => 'Draft']);
    $draft->update(['name' => 'Draft Two']);

    // The default query applies the `published` global scope, so the draft stays hidden...
    expect(Slugs::model(ScopedArticle::class)->findInHistory('draft'))->toBeNull()
        // ...unless the caller's own query can see it,
        ->and(Slugs::model(ScopedArticle::class)->findInHistory('draft', within: ScopedArticle::query()->withoutGlobalScopes())?->is($draft))->toBeTrue()
        // and a query scoped to another tenant never reveals it.
        ->and(Slugs::model(ScopedArticle::class)->findInHistory('draft', within: ScopedArticle::query()->withoutGlobalScopes()->where('tenant_id', 99)))->toBeNull();
});

it('exposes the resolved definitions of a model', function (): void {
    expect(Slugs::model(LocalizedPage::class)->options()->default()->isLocalized())->toBeTrue();
});

it('recomputes one model without saving, and regenerates with a save', function (): void {
    $article = Article::query()->create(['name' => 'First']);
    $article->name = 'Second';

    $result = Slugs::recompute($article);

    expect($result->changes)->toHaveCount(1)
        ->and($article->slug)->toBe('second')
        ->and($article->fresh()?->slug)->toBe('first');

    $article->name = 'Third';

    expect(Slugs::regenerate($article)->fresh()?->slug)->toBe('third')
        ->and(fn () => Slugs::recompute(new Brand))->toThrow(InvalidSlugDefinitionException::class)
        ->and(fn () => Slugs::regenerate(new Brand))->toThrow(InvalidSlugDefinitionException::class);
});

it('serves the same API to an injected manager', function (): void {
    DB::table('articles')->insert(['name' => 'Injected']);

    $manager = app(SlugManager::class);

    expect($manager)->toBe(Slugs::getFacadeRoot())
        ->and($manager->model(Article::class)->regenerate()->changed)->toBe(1)
        ->and(Article::query()->value('slug'))->toBe('injected');
});
