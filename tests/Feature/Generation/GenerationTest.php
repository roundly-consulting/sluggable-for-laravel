<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Enums\EmptySourcePolicy;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\Exceptions\SlugGenerationException;
use RoundlyConsulting\Sluggable\Facades\Slugs;
use RoundlyConsulting\Sluggable\Tests\Fixtures\Article;
use RoundlyConsulting\Sluggable\Tests\Fixtures\Brand;
use RoundlyConsulting\Sluggable\Tests\Fixtures\DefaultThing;
use RoundlyConsulting\Sluggable\Tests\Fixtures\MultiSlugThing;
use RoundlyConsulting\Sluggable\Tests\Fixtures\PlainThing;

it('generates from the config defaults with zero configuration', function (): void {
    expect(DefaultThing::query()->create(['name' => 'Zero Config'])->slug)->toBe('zero-config');
});

it('suffixes collisions sequentially from -2', function (): void {
    $slugs = collect(range(1, 4))->map(fn (): ?string => Article::query()->create(['name' => 'Same'])->slug);

    expect($slugs->all())->toBe(['same', 'same-2', 'same-3', 'same-4']);
});

it('starts suffixes where the definition says', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->sequentialSuffix(start: 1));

    Article::query()->create(['name' => 'Same']);

    expect(Article::query()->create(['name' => 'Same'])->slug)->toBe('same-1');
});

it('generates each column independently, in declaration order', function (): void {
    $brand = Brand::query()->create(['name' => 'Acme Tools']);

    $thing = MultiSlugThing::query()->create(['name' => 'Red Chair', 'sku' => 'ab 12', 'brand_id' => $brand->id]);

    expect($thing->slug)->toBe('red-chair')
        ->and($thing->handle)->toBe('acme_tools_red_chair')
        ->and($thing->code)->toBe('X-ab-12');
});

it('loads relation sources explicitly even with lazy loading prevented', function (): void {
    Model::preventLazyLoading();

    try {
        $brand = Brand::query()->create(['name' => 'Lazy']);
        $thing = MultiSlugThing::query()->create(['name' => 'Seat', 'sku' => '1', 'brand_id' => $brand->id]);

        expect($thing->handle)->toBe('lazy_seat');
    } finally {
        Model::preventLazyLoading(false);
    }
});

it('treats a missing relation as an empty source part', function (): void {
    expect(MultiSlugThing::query()->create(['name' => 'Orphan', 'sku' => '1'])->handle)->toBe('orphan');
});

it('lets a later definition use an earlier slug as its source', function (): void {
    definitionFor(
        MultiSlugThing::class,
        SlugDefinition::for('slug')->from('name'),
        SlugDefinition::for('handle')->from('slug')->prefix('h'),
    );

    expect(MultiSlugThing::query()->create(['name' => 'Chained'])->handle)->toBe('h-chained');
});

it('applies static and closure affixes without cutting them', function (): void {
    definitionFor(
        Article::class,
        SlugDefinition::for('slug')->from('name')->maxLength(20)
            ->prefix(fn (Article $article): string => 'T'.$article->tenant_id)
            ->suffix('Final'),
    );

    $slug = Article::query()->create(['name' => 'A title that is far too long', 'tenant_id' => 7])->slug;

    expect($slug)->toStartWith('t7-')->toEndWith('-final')
        ->and(mb_strlen((string) $slug))->toBeLessThanOrEqual(20);
});

it('reads a closure source per save', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from(fn (Article $article, ?string $locale): string => $article->title.' '.$article->code));

    expect(Article::query()->create(['title' => 'Joined', 'code' => 'xy'])->slug)->toBe('joined-xy');
});

it('mints a random slug for an empty source by default', function (): void {
    expect(Article::query()->create(['name' => '###'])->slug)->toMatch('/^[a-z0-9]{8}$/');
});

it('mints a mixed-case random slug when case is kept', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->randomLength(12)->lowercase(false));

    expect(Article::query()->create(['name' => null])->slug)->toMatch('/^[A-Za-z0-9]{12}$/');
});

it('skips generation for an empty source when asked', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->whenEmptySource(EmptySourcePolicy::Skip));

    expect(Article::query()->create(['name' => ''])->slug)->toBeNull();
});

it('fails loudly for an empty source when asked', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->whenEmptySource(EmptySourcePolicy::Fail));

    Article::query()->create(['name' => '   ']);
})->throws(SlugGenerationException::class, 'empty slug');

it('does not generate when onCreate is off', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->onCreate(false));

    expect(Article::query()->create(['name' => 'Off'])->slug)->toBeNull();
});

it('skips a column while skipWhen holds', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->skipWhen(fn (Article $article): bool => $article->code === 'skip'));

    expect(Article::query()->create(['name' => 'Skipped', 'code' => 'skip'])->slug)->toBeNull()
        ->and(Article::query()->create(['name' => 'Kept'])->slug)->toBe('kept');
});

it('treats reserved words as taken', function (): void {
    config(['sluggable.reserved' => ['create']]);

    expect(Article::query()->create(['name' => 'Create'])->slug)->toBe('create-2');
});

it('honours definition-level reserved words', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->reserved(['edit']));

    expect(Article::query()->create(['name' => 'Edit'])->slug)->toBe('edit-2');
});

it('does not generate inside withoutGeneration and restores the flag on exceptions', function (): void {
    Slugs::withoutGeneration(function (): void {
        expect(Article::query()->create(['name' => 'Imported'])->slug)->toBeNull()
            ->and(Slugs::isGenerationEnabled())->toBeFalse();

        Article::withoutSlugGeneration(fn () => expect(Slugs::isGenerationEnabled())->toBeFalse());
    });

    expect(fn () => Slugs::withoutGeneration(fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class)
        ->and(Slugs::isGenerationEnabled())->toBeTrue()
        ->and(Article::query()->create(['name' => 'After'])->slug)->toBe('after');
});

it('applies generation before a quiet save', function (): void {
    $article = new Article(['name' => 'Quiet']);

    $result = Slugs::apply($article);
    $article->saveQuietly();

    expect($article->fresh()?->slug)->toBe('quiet')
        ->and($result->changes)->toHaveCount(1);
});

it('does not generate for quiet saves on its own', function (): void {
    $article = new Article(['name' => 'Silent']);
    $article->saveQuietly();

    expect($article->slug)->toBeNull();
});

it('generates a value on demand without writing it', function (): void {
    Article::query()->create(['name' => 'Demand']);

    $fresh = new Article(['name' => 'Demand']);

    expect(Slugs::generate($fresh))->toBe('demand-2')
        ->and($fresh->slug)->toBeNull()
        ->and(fn () => Slugs::generate($fresh, locale: 'en'))->toThrow(InvalidSlugDefinitionException::class);
});

it('generates a random value on demand for an empty source', function (): void {
    expect(Slugs::generate(new Article))->toMatch('/^[a-z0-9]{8}$/');

    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->whenEmptySource(EmptySourcePolicy::Skip));

    expect(fn () => Slugs::generate(new Article))->toThrow(SlugGenerationException::class);
});

it('slugifies text through the facade', function (): void {
    expect(Slugs::slugify('Žltý kôň @ home', language: 'sk'))->toBe('zlty-kon-at-home')
        ->and(Slugs::slugify('Hello World', separator: '_'))->toBe('hello_world')
        ->and(fn () => Slugs::slugify('x', separator: '/'))->toThrow(InvalidSlugDefinitionException::class);
});

it('exposes definitions and locales through the facade', function (): void {
    expect(Slugs::options(Article::class)->default()->column)->toBe('slug')
        ->and(Slugs::options(new PlainThing)->columns())->toBe(['slug'])
        ->and(Slugs::locales()->supported())->toBe(['en', 'sk', 'de']);
});
