<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Sluggable\Actions\RegenerateSlugsAction;
use RoundlyConsulting\Sluggable\DataTransferObjects\RegenerateSlugsData;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Enums\RegenerationMode;
use RoundlyConsulting\Sluggable\Events\SlugsRegenerated;
use RoundlyConsulting\Sluggable\Jobs\RegenerateSlugsJob;
use RoundlyConsulting\Sluggable\Models\SlugHistory;
use RoundlyConsulting\Sluggable\Tests\Fixtures\Article;
use RoundlyConsulting\Sluggable\Tests\Fixtures\Brand;
use RoundlyConsulting\Sluggable\Tests\Fixtures\LocalizedPage;
use RoundlyConsulting\Sluggable\Tests\Fixtures\ScopedItem;

it('backfills missing slugs by default', function (): void {
    DB::table('articles')->insert([['name' => 'Legacy One'], ['name' => 'Legacy Two']]);
    Event::fake([SlugsRegenerated::class]);

    $this->artisan('sluggable:regenerate', ['model' => Article::class])
        ->expectsOutputToContain('Changed 2 of 2 row(s).')
        ->assertSuccessful();

    expect(Article::query()->pluck('slug')->sort()->values()->all())->toBe(['legacy-one', 'legacy-two']);
    Event::assertDispatched(SlugsRegenerated::class, fn (SlugsRegenerated $event): bool => $event->report->changed === 2);
});

it('previews changes on a dry run and writes nothing', function (): void {
    DB::table('articles')->insert(['name' => 'Dry']);

    $this->artisan('sluggable:regenerate', ['model' => Article::class, '--dry-run' => true])
        ->expectsTable(['Key', 'Column', 'Locale', 'Old', 'New'], [[(string) DB::table('articles')->value('id'), 'slug', '', '', 'dry']])
        ->expectsOutputToContain('Would change 1 of 1 row(s).')
        ->assertSuccessful();

    expect(DB::table('articles')->value('slug'))->toBeNull();
});

it('regenerates stale slugs, respecting locks unless forced, with history on request', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->lockWhen(fn (Article $article): bool => (bool) $article->published));

    $open = Article::query()->create(['name' => 'Open']);
    $locked = Article::query()->create(['name' => 'Locked', 'published' => true]);
    foreach (DB::table('articles')->get() as $row) {
        DB::table('articles')->where('id', $row->id)->update(['name' => $row->name.' Renamed']);
    }

    $this->artisan('sluggable:regenerate', ['model' => Article::class, '--mode' => 'stale', '--history' => true])->assertSuccessful();

    expect($open->fresh()?->slug)->toBe('open-renamed')
        ->and($locked->fresh()?->slug)->toBe('locked')
        ->and(SlugHistory::query()->where('slug', 'open')->exists())->toBeTrue();

    $this->artisan('sluggable:regenerate', ['model' => Article::class, '--mode' => 'stale', '--force' => true])->assertSuccessful();

    expect($locked->fresh()?->slug)->toBe('locked-renamed');
});

it('asks before rewriting everything and aborts without consent', function (): void {
    Article::query()->create(['name' => 'Asked']);

    $this->artisan('sluggable:regenerate', ['model' => Article::class, '--mode' => 'all'])
        ->expectsConfirmation('--mode=all rewrites existing slugs (and therefore URLs). Continue?', 'no')
        ->assertFailed();

    $this->artisan('sluggable:regenerate', ['model' => Article::class, '--mode' => 'all'])
        ->expectsConfirmation('--mode=all rewrites existing slugs (and therefore URLs). Continue?', 'yes')
        ->assertSuccessful();
});

it('backfills a new locale without touching the others', function (): void {
    $page = LocalizedPage::query()->create(['name' => ['en' => 'Hello', 'de' => 'Hallo']]);
    DB::table('localized_pages')->update(['slug' => json_encode(['en' => 'hello'])]);

    $this->artisan('sluggable:regenerate', ['model' => LocalizedPage::class, '--locale' => ['de'], '--without-events' => true])->assertSuccessful();

    expect($page->fresh()?->slug)->toEqual(['en' => 'hello', 'de' => 'hallo']);
});

it('rejects bad arguments', function (array $arguments): void {
    $this->artisan('sluggable:regenerate', $arguments)->assertFailed();
})->with([
    'not a model' => [['model' => 'Nope\\Nothing']],
    'not sluggable' => [['model' => Brand::class]],
    'bad mode' => [['model' => Article::class, '--mode' => 'everything']],
    'bad chunk' => [['model' => Article::class, '--chunk' => '0']],
    'bad locale' => [['model' => Article::class, '--locale' => ['EN!']]],
]);

it('resolves a morph alias', function (): void {
    Relation::morphMap(['article' => Article::class]);

    try {
        $this->artisan('sluggable:regenerate', ['model' => 'article'])->assertSuccessful();
    } finally {
        Relation::morphMap([], false);
    }
});

it('dispatches one job per chunk on --queue, and the job regenerates its keys', function (): void {
    DB::table('articles')->insert([['name' => 'Q1'], ['name' => 'Q2'], ['name' => 'Q3']]);
    Bus::fake();

    $this->artisan('sluggable:regenerate', ['model' => Article::class, '--queue' => true, '--chunk' => '2'])
        ->expectsOutputToContain('Dispatched 2 job(s).')
        ->assertSuccessful();

    Bus::assertDispatchedTimes(RegenerateSlugsJob::class, 2);

    $keys = DB::table('articles')->orderBy('id')->limit(2)->pluck('id')->all();
    (new RegenerateSlugsJob(new RegenerateSlugsData(Article::class), $keys))->handle(app(RegenerateSlugsAction::class));

    expect(DB::table('articles')->whereNotNull('slug')->count())->toBe(2);
});

it('regenerates through the action with an explicit mode', function (): void {
    $article = Article::query()->create(['name' => 'Base']);
    $article->forceFill(['slug' => 'base-7'])->saveQuietly();

    $report = app(RegenerateSlugsAction::class)->execute(new RegenerateSlugsData(Article::class, mode: RegenerationMode::All, columns: ['slug']));

    expect($report->changed)->toBe(1)->and($article->fresh()?->slug)->toBe('base');
});

it('creates and reports a model\'s indexes', function (): void {
    $this->artisan('sluggable:indexes', ['model' => LocalizedPage::class])
        ->expectsOutputToContain('Exists: localized_pages_slug_en_slug_unique')
        ->expectsOutputToContain('Created 0 index(es), 3 already present.')
        ->assertSuccessful();

    dropSlugIndex('scoped_items', 'scoped_items_slug_slug_unique');

    $this->artisan('sluggable:indexes', ['model' => ScopedItem::class, '--dry-run' => true])
        ->expectsOutputToContain('Would create 1 index(es)')
        ->assertSuccessful();

    $this->artisan('sluggable:indexes', ['model' => ScopedItem::class, '--column' => ['slug']])
        ->expectsOutputToContain('Created 1 index(es)')
        ->assertSuccessful();
});

it('fails the index command on bad input', function (): void {
    $this->artisan('sluggable:indexes', ['model' => 'Nope'])->assertFailed();

    config(['sluggable.locales.supported' => ['pt-BR', 'pt_BR']]);
    $this->artisan('sluggable:indexes', ['model' => LocalizedPage::class])->assertFailed();
});

it('reports duplicates and over-long slugs', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->maxLength(20));
    dropSlugIndex('articles', 'articles_slug_slug_unique');
    DB::table('articles')->insert([['slug' => 'dup'], ['slug' => 'dup'], ['slug' => 'unique'], ['slug' => str_repeat('x', 30)]]);

    $this->artisan('sluggable:duplicates', ['model' => Article::class])
        ->expectsOutputToContain('duplicate')
        ->expectsOutputToContain('too long')
        ->expectsOutputToContain('2 problem(s) found.')
        ->assertSuccessful();
});

it('reports locale-map duplicates per locale and scope', function (): void {
    dropSlugIndex('localized_pages', 'localized_pages_slug_en_slug_unique');
    DB::table('localized_pages')->insert([
        ['shop_id' => 1, 'slug' => json_encode(['en' => 'same', 'sk' => 'a'])],
        ['shop_id' => 1, 'slug' => json_encode(['en' => 'same', 'sk' => 'b'])],
    ]);

    $this->artisan('sluggable:duplicates', ['model' => LocalizedPage::class, '--locale' => 'sk'])
        ->expectsOutputToContain('No duplicate or over-length slugs found.')
        ->assertSuccessful();

    $this->artisan('sluggable:duplicates', ['model' => LocalizedPage::class, '--column' => 'slug', '--locale' => 'en'])
        ->expectsOutputToContain('1 problem(s) found.')
        ->assertSuccessful();
});

it('fails the duplicates command on bad input', function (): void {
    $this->artisan('sluggable:duplicates', ['model' => 'Nope'])->assertFailed();
    $this->artisan('sluggable:duplicates', ['model' => Article::class, '--locale' => 'EN!'])->assertFailed();
});
