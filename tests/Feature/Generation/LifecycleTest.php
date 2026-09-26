<?php

declare(strict_types=1);

use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Enums\EmptySourcePolicy;
use RoundlyConsulting\Sluggable\Enums\ManualSlugPolicy;
use RoundlyConsulting\Sluggable\Enums\UpdatePolicy;
use RoundlyConsulting\Sluggable\Exceptions\SlugAlreadyTakenException;
use RoundlyConsulting\Sluggable\Exceptions\SlugGenerationException;
use RoundlyConsulting\Sluggable\Exceptions\SlugLockedException;
use RoundlyConsulting\Sluggable\Facades\Slugs;
use RoundlyConsulting\Sluggable\Tests\Fixtures\Article;
use RoundlyConsulting\Sluggable\Tests\Fixtures\LocalizedPage;
use RoundlyConsulting\Sluggable\Tests\Fixtures\ScopedItem;

it('keeps an existing slug on update by default (IfEmpty)', function (): void {
    $article = Article::query()->create(['name' => 'First']);
    $article->update(['name' => 'Second']);

    expect($article->slug)->toBe('first');
});

it('fills an emptied slug on update (IfEmpty)', function (): void {
    $article = Article::query()->create(['name' => 'First']);
    $article->update(['slug' => null, 'name' => 'Second']);

    expect($article->slug)->toBe('second');
});

it('never touches the slug on update with Never, even when empty', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->immutable());

    $article = Article::query()->create(['name' => 'First']);
    $article->update(['slug' => null]);

    expect($article->slug)->toBeNull();
});

it('regenerates only when the source changes (WhenSourceChanges)', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->regenerateOnUpdate());

    $article = Article::query()->create(['name' => 'First']);
    $article->update(['code' => 'unrelated']);
    expect($article->slug)->toBe('first');

    $article->update(['name' => 'Second']);
    expect($article->slug)->toBe('second');
});

it('does not churn suffixes when the base is unchanged', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->onUpdate(UpdatePolicy::Always));

    Article::query()->create(['name' => 'Chair']);
    $second = Article::query()->create(['name' => 'Chair']);

    $second->update(['code' => 'x']);
    $second->update(['name' => 'Chair ']);

    expect($second->slug)->toBe('chair-2');
});

it('does not churn a random fallback suffix once the sequential probes ran out', function (): void {
    config(['sluggable.limits.sequential_probes' => 1]);
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->onUpdate(UpdatePolicy::Always));

    Article::query()->create(['name' => 'Chair']);
    Article::query()->create(['name' => 'Chair']);
    $third = Article::query()->create(['name' => 'Chair']);
    $fallback = $third->slug;

    $third->update(['code' => 'touch']);

    expect($fallback)->toMatch('/^chair-[a-z0-9]{8}$/')
        ->and($third->slug)->toBe($fallback)
        ->and($third->regenerateSlugs()->slug)->toBe($fallback);
});

it('recomputes on every update with Always (picking up option changes)', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->onUpdate(UpdatePolicy::Always));
    $article = Article::query()->create(['name' => 'Hello World']);

    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->onUpdate(UpdatePolicy::Always)->separator('_'));
    $article->update(['code' => 'touch']);

    expect($article->slug)->toBe('hello_world');
});

it('keeps an existing slug when the source is emptied', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->onUpdate(UpdatePolicy::Always));
    $article = Article::query()->create(['name' => 'Named']);
    $article->update(['name' => '']);

    expect($article->slug)->toBe('named');
});

it('does not back-fill on a no-op save', function (): void {
    $article = Slugs::withoutGeneration(fn () => Article::query()->create(['name' => 'Legacy']));
    $article->save();

    expect($article->fresh()?->slug)->toBeNull();

    $article->regenerateSlugs()->save();

    expect($article->fresh()?->slug)->toBe('legacy');
});

it('normalises and uniquifies manual slugs (Normalize)', function (): void {
    Article::query()->create(['name' => 'Taken', 'slug' => 'custom-slug']);

    $article = Article::query()->create(['name' => 'x', 'slug' => 'Custom Slug!']);

    expect($article->slug)->toBe('custom-slug-2');
});

it('falls back to generation when a manual slug normalises to nothing', function (): void {
    expect(Article::query()->create(['name' => 'From Name', 'slug' => '###'])->slug)->toBe('from-name');
});

it('never persists a manual slug that normalises to nothing when nothing is generated', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->immutable());
    $immutable = Article::query()->create(['name' => 'First']);
    $immutable->update(['slug' => '???']);

    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->onCreate(false));
    $notGenerated = Article::query()->create(['name' => 'Second', 'slug' => '/#?']);

    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->whenEmptySource(EmptySourcePolicy::Skip));
    $skipped = Article::query()->create(['name' => '', 'slug' => '%%%']);

    expect($immutable->fresh()?->slug)->toBeNull()
        ->and($notGenerated->fresh()?->slug)->toBeNull()
        ->and($skipped->fresh()?->slug)->toBeNull();
});

it('drops a manual locale that normalises to nothing when that locale is not generated', function (): void {
    definitionFor(LocalizedPage::class, SlugDefinition::for('slug')->from('name')->whenEmptySource(EmptySourcePolicy::Skip));

    $page = LocalizedPage::query()->create(['name' => ['en' => 'Table'], 'slug' => ['sk' => '?#/']]);

    expect($page->fresh()?->slug)->toBe(['en' => 'table']);
});

it('keeps manual bytes but uniquifies them (Verbatim)', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->manual(ManualSlugPolicy::Verbatim));

    Article::query()->create(['slug' => 'Mixed_Case']);

    expect(Article::query()->create(['slug' => 'Mixed_Case'])->slug)->toBe('Mixed_Case-2');
});

it('rejects unsafe verbatim values', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->manual(ManualSlugPolicy::Verbatim));

    Article::query()->create(['slug' => 'a b/c']);
})->throws(SlugGenerationException::class, 'contains whitespace');

it('rejects a verbatim dot segment, which browsers resolve away', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->manual(ManualSlugPolicy::Strict));

    Article::query()->create(['slug' => '..']);
})->throws(SlugGenerationException::class, 'contains whitespace');

it('throws on a taken strict manual slug', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->manual(ManualSlugPolicy::Strict));

    Article::query()->create(['slug' => 'sidebar']);

    expect(Article::query()->create(['slug' => 'header'])->slug)->toBe('header');

    Article::query()->create(['slug' => 'sidebar']);
})->throws(SlugAlreadyTakenException::class, 'already taken');

it('applies the manual policy to manual changes on update', function (): void {
    Article::query()->create(['name' => 'One']);
    $article = Article::query()->create(['name' => 'Two']);

    $article->update(['slug' => 'One']);

    expect($article->slug)->toBe('one-2');
});

it('locks slugs while lockWhen holds and throws on manual changes', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->regenerateOnUpdate()->lockWhen(fn (Article $article): bool => $article->published));

    $article = Article::query()->create(['name' => 'Draft', 'published' => true]);
    expect($article->slug)->toBe('draft');

    $article->update(['name' => 'Renamed']);
    expect($article->slug)->toBe('draft');

    expect(fn () => $article->update(['slug' => 'manual']))->toThrow(SlugLockedException::class);

    Slugs::unlocked(fn () => $article->update(['slug' => 'manual']));
    expect($article->fresh()?->slug)->toBe('manual')
        ->and(Slugs::isUnlocked())->toBeFalse();
});

it('treats a locked() definition as always locked on update', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->locked()->onUpdate(UpdatePolicy::Always));

    $article = Article::query()->create(['name' => 'Fixed']);
    $article->update(['name' => 'Other']);

    expect($article->slug)->toBe('fixed');
});

it('re-suffixes a replicated model', function (): void {
    $original = Article::query()->create(['name' => 'Twin']);
    $copy = $original->replicate();
    $copy->save();

    expect($copy->slug)->toBe('twin-2');
});

it('re-checks uniqueness when a scope column changes', function (): void {
    ScopedItem::query()->create(['name' => 'Moved', 'tenant_id' => 2]);
    $item = ScopedItem::query()->create(['name' => 'Moved', 'tenant_id' => 1]);

    expect($item->slug)->toBe('moved');

    $item->update(['tenant_id' => 2]);

    expect($item->slug)->toBe('moved-2');
});

it('throws for a strict slug that collides after a scope change', function (): void {
    definitionFor(ScopedItem::class, SlugDefinition::for('slug')->from('name')->uniqueWithin('tenant_id')->manual(ManualSlugPolicy::Strict));

    ScopedItem::query()->create(['name' => 'Moved', 'tenant_id' => 2]);
    $item = ScopedItem::query()->create(['name' => 'Moved', 'tenant_id' => 1]);

    $item->update(['tenant_id' => 2]);
})->throws(SlugAlreadyTakenException::class);

it('re-suffixes a restored model whose slug was reused while trashed (excludeTrashed)', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->excludeTrashed());
    $old = Article::query()->create(['name' => 'Phoenix']);
    $old->delete();

    // Real engines carry the include-trashed index from the fixture migration, which the
    // exclude-trashed definition disagrees with; drop it for this scenario.
    dropSlugIndex('articles', 'articles_slug_slug_unique');

    $new = Article::query()->create(['name' => 'Phoenix']);
    expect($new->slug)->toBe('phoenix');

    $old->restore();

    expect($old->fresh()?->slug)->toBe('phoenix-2');
});

it('regenerates on demand, respecting the fingerprint', function (): void {
    $article = Article::query()->create(['name' => 'Before']);
    $article->name = 'After';

    expect($article->regenerateSlugs()->slug)->toBe('after');

    $article->save();
    expect($article->fresh()?->slug)->toBe('after');

    expect(Slugs::regenerate($article)->slug)->toBe('after');
});
