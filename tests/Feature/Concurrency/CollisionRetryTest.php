<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Enums\ManualSlugPolicy;
use RoundlyConsulting\Sluggable\Events\SlugCollisionRetried;
use RoundlyConsulting\Sluggable\Exceptions\SlugAlreadyTakenException;
use RoundlyConsulting\Sluggable\Exceptions\SlugGenerationException;
use RoundlyConsulting\Sluggable\Tests\Fixtures\Article;
use RoundlyConsulting\Sluggable\Tests\Fixtures\LocalizedPage;
use RoundlyConsulting\Sluggable\Tests\Fixtures\ScopedItem;

/**
 * A racing writer, without fakes: a creating/updating listener registered AFTER HasSlug inserts a
 * row holding the value sluggable just picked, so the real INSERT hits the real unique index.
 */
function raceOnce(string $event, string $class = Article::class, ?Closure $row = null): void
{
    $fired = false;

    // Boot the model first, so HasSlug's own listener is registered BEFORE the racing one.
    new $class;

    $class::$event(function ($model) use (&$fired, $row): void {
        if ($fired) {
            return;
        }

        $fired = true;
        $row !== null ? $row($model) : DB::table('articles')->insert(['slug' => $model->slug]);
    });
}

it('retries an insert with the next suffix after a race', function (): void {
    Event::fake([SlugCollisionRetried::class]);
    raceOnce('creating');

    $article = Article::query()->create(['name' => 'Race']);

    expect($article->slug)->toBe('race-2')
        ->and(Article::query()->where('slug', 'race')->exists())->toBeTrue();

    Event::assertDispatched(SlugCollisionRetried::class, fn (SlugCollisionRetried $event): bool => $event->column === 'slug' && $event->attempt === 1);
});

it('recognises a scoped unique index under its default Laravel name on every engine', function (): void {
    dropSlugIndex('scoped_items', 'scoped_items_slug_slug_unique');
    Schema::table('scoped_items', fn (Blueprint $table) => $table->unique(['tenant_id', 'slug']));
    raceOnce('creating', ScopedItem::class, fn (ScopedItem $item) => DB::table('scoped_items')->insert(['slug' => $item->slug, 'tenant_id' => $item->tenant_id]));

    expect(ScopedItem::query()->create(['name' => 'Race', 'tenant_id' => 1])->slug)->toBe('race-2');
});

it('keeps the outer transaction usable after a violation (savepoint per attempt)', function (): void {
    Article::query()->create(['name' => 'Owner', 'email' => 'taken@example.com']);

    DB::transaction(function (): void {
        try {
            Article::query()->create(['name' => 'Dup', 'email' => 'taken@example.com']);
        } catch (UniqueConstraintViolationException) {
            // Postgres would now reject every statement of an unprotected transaction.
        }

        expect(Article::query()->create(['name' => 'Still Works'])->slug)->toBe('still-works');
    });

    expect(Article::query()->where('slug', 'still-works')->exists())->toBeTrue();
});

it('retries inside an outer transaction against a row another connection committed', function (): void {
    config(['database.connections.racer' => config('database.connections.testing')]);
    raceOnce('creating', row: fn (Article $model) => DB::connection('racer')->table('articles')->insert(['slug' => $model->slug]));

    DB::transaction(function (): void {
        $article = Article::query()->create(['name' => 'Txn']);
        $other = Article::query()->create(['name' => 'After Retry']);

        expect($article->slug)->toBe('txn-2')->and($other->slug)->toBe('after-retry');
    });

    expect(Article::query()->where('slug', 'txn-2')->exists())->toBeTrue();
})->skip(fn (): bool => onDriver('sqlite'), 'needs a second connection to a shared real engine');

it('retries an update after a race', function (): void {
    $article = Article::query()->create(['name' => 'Up']);
    $article->slug = null;
    raceOnce('updating', row: fn (Article $model) => DB::table('articles')->insert(['slug' => 'down']));
    $article->name = 'Down';
    $article->save();

    expect($article->fresh()?->slug)->toBe('down-2');
});

it('gives up after the configured retries', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->retries(2));

    new Article;
    Article::creating(function (Article $model): void {
        DB::table('articles')->insert(['slug' => $model->slug]);
    });

    Article::query()->create(['name' => 'Always']);
})->throws(SlugGenerationException::class, 'after every retry');

it('rethrows untouched when retries are disabled', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->retries(0));
    raceOnce('creating');

    Article::query()->create(['name' => 'Off']);
})->throws(UniqueConstraintViolationException::class);

it('rethrows a foreign unique violation untouched', function (): void {
    Article::query()->create(['name' => 'A', 'email' => 'x@example.com']);

    Article::query()->create(['name' => 'B', 'email' => 'x@example.com']);
})->throws(UniqueConstraintViolationException::class);

it('reports a definition/index mismatch instead of looping', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->excludeTrashed());
    Article::query()->create(['name' => 'Ghost'])->delete();

    Article::query()->create(['name' => 'Ghost']);
})->throws(SlugGenerationException::class, 'unique index disagrees');

it('throws for a strict manual slug that loses the race', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->manual(ManualSlugPolicy::Strict));
    raceOnce('creating');

    Article::query()->create(['slug' => 'strict']);
})->throws(SlugAlreadyTakenException::class);

it('re-suffixes a racing locale-map slug', function (): void {
    raceOnce('creating', LocalizedPage::class, fn (LocalizedPage $page) => DB::table('localized_pages')->insert(['slug' => json_encode(['en' => 'table'])]));

    $page = LocalizedPage::query()->create(['name' => ['en' => 'Table', 'sk' => 'Stôl']]);

    expect($page->slug)->toBe(['en' => 'table-2', 'sk' => 'stol']);
});
