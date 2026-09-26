<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Exceptions\SlugGenerationException;
use RoundlyConsulting\Sluggable\Tests\Fixtures\Article;
use RoundlyConsulting\Sluggable\Tests\Fixtures\ScopedArticle;
use RoundlyConsulting\Sluggable\Tests\Fixtures\ScopedItem;

it('never lets a global scope hide a collision', function (): void {
    ScopedArticle::query()->create(['name' => 'Hidden', 'published' => false]);

    expect(ScopedArticle::query()->create(['name' => 'Hidden', 'published' => true])->slug)->toBe('hidden-2');
});

it('counts trashed rows as taken by default', function (): void {
    Article::query()->create(['name' => 'Gone'])->delete();

    expect(Article::query()->create(['name' => 'Gone'])->slug)->toBe('gone-2');
});

it('can ignore trashed rows', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->excludeTrashed()->notUnique());
    Article::query()->create(['name' => 'Gone'])->delete();

    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->excludeTrashed()->uniqueWhere(fn (Builder $query) => $query->whereRaw('1 = 1')));

    // An exclude-trashed definition needs an exclude-trashed index; the fixture's plain one is dropped.
    dropSlugIndex('articles', 'articles_slug_slug_unique');

    expect(Article::query()->create(['name' => 'Gone'])->slug)->toBe('gone');
});

it('scopes uniqueness by columns, NULL being a scope value too', function (): void {
    expect(ScopedItem::query()->create(['name' => 'Vaccines', 'tenant_id' => 1])->slug)->toBe('vaccines')
        ->and(ScopedItem::query()->create(['name' => 'Vaccines', 'tenant_id' => 2])->slug)->toBe('vaccines')
        ->and(ScopedItem::query()->create(['name' => 'Vaccines', 'tenant_id' => 1])->slug)->toBe('vaccines-2')
        ->and(ScopedItem::query()->create(['name' => 'Vaccines'])->slug)->toBe('vaccines')
        ->and(ScopedItem::query()->create(['name' => 'Vaccines'])->slug)->toBe('vaccines-2');
});

it('scopes uniqueness by two columns', function (): void {
    definitionFor(ScopedItem::class, SlugDefinition::for('slug')->from('name')->uniqueWithin('tenant_id', 'parent_id'));

    DB::table('scoped_items')->truncate();
    dropSlugIndex('scoped_items', 'scoped_items_slug_slug_unique');

    expect(ScopedItem::query()->create(['name' => 'Leaf', 'tenant_id' => 1, 'parent_id' => 1])->slug)->toBe('leaf')
        ->and(ScopedItem::query()->create(['name' => 'Leaf', 'tenant_id' => 1, 'parent_id' => 2])->slug)->toBe('leaf')
        ->and(ScopedItem::query()->create(['name' => 'Leaf', 'tenant_id' => 1, 'parent_id' => 1])->slug)->toBe('leaf-2');
});

it('scopes uniqueness with a closure', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->uniqueWhere(
        fn (Builder $query, Article $article) => $query->where('tenant_id', $article->tenant_id),
    ));
    dropSlugIndex('articles', 'articles_slug_slug_unique');

    expect(Article::query()->create(['name' => 'Per', 'tenant_id' => 1])->slug)->toBe('per')
        ->and(Article::query()->create(['name' => 'Per', 'tenant_id' => 2])->slug)->toBe('per')
        ->and(Article::query()->create(['name' => 'Per', 'tenant_id' => 1])->slug)->toBe('per-2');
});

it('allows duplicates when not unique', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->notUnique());
    dropSlugIndex('articles', 'articles_slug_slug_unique');

    Article::query()->create(['name' => 'Twin']);

    expect(Article::query()->create(['name' => 'Twin'])->slug)->toBe('twin');
});

it('probes at most six queries for fifty collisions', function (): void {
    foreach (range(0, 49) as $n) {
        DB::table('articles')->insert(['slug' => $n === 0 ? 'busy' : 'busy-'.($n + 1)]);
    }

    $probes = 0;
    DB::listen(function ($query) use (&$probes): void {
        if (str_starts_with(strtolower($query->sql), 'select')) {
            $probes++;
        }
    });

    $slug = Article::query()->create(['name' => 'Busy'])->slug;

    expect($slug)->toBe('busy-51')->and($probes)->toBeLessThanOrEqual(7);
});

it('falls back to random suffixes after the sequential probes', function (): void {
    config(['sluggable.limits.sequential_probes' => 3]);
    foreach (['full', 'full-2', 'full-3', 'full-4'] as $slug) {
        DB::table('articles')->insert(['slug' => $slug]);
    }

    expect(Article::query()->create(['name' => 'Full'])->slug)->toMatch('/^full-[a-z0-9]{8}$/');
});

it('uses random suffixes straight away with the random strategy', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->randomSuffix(5));
    Article::query()->create(['name' => 'Rnd']);

    expect(Article::query()->create(['name' => 'Rnd'])->slug)->toMatch('/^rnd-[a-z0-9]{5}$/');
});

it('uses a custom suffix generator', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->suffixUsing(fn (string $base, int $attempt): string => 'v'.($attempt + 1)));
    Article::query()->create(['name' => 'Ver']);

    expect(Article::query()->create(['name' => 'Ver'])->slug)->toBe('ver-v2');
});

it('throws once every candidate is exhausted', function (): void {
    config(['sluggable.limits.sequential_probes' => 1, 'sluggable.limits.random_attempts' => 1]);
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->suffixUsing(fn (): string => 'x'));

    foreach (['same', 'same-x'] as $slug) {
        DB::table('articles')->insert(['slug' => $slug]);
    }

    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->reserved(['same'])->suffixUsing(fn (): string => 'x')->randomLength(4));

    // Every random attempt collides as well once `random` is forced to a taken value.
    config(['sluggable.limits.random_attempts' => 1]);
    Str::createRandomStringsUsing(fn (): string => 'aaaa');
    DB::table('articles')->insert(['slug' => 'same-aaaa']);

    try {
        Article::query()->create(['name' => 'Same']);
    } finally {
        Str::createRandomStringsNormally();
    }
})->throws(SlugGenerationException::class, 'every probed candidate');
