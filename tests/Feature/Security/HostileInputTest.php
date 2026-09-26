<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Exceptions\InvalidLocaleException;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\Exceptions\SlugGenerationException;
use RoundlyConsulting\Sluggable\Facades\Slugs;
use RoundlyConsulting\Sluggable\Rules\UniqueSlug;
use RoundlyConsulting\Sluggable\Tests\Fixtures\Article;
use RoundlyConsulting\Sluggable\Tests\Fixtures\LocalizedPage;
use RoundlyConsulting\Sluggable\Tests\Fixtures\ScopedItem;

it('refuses a hostile locale in scopes before any SQL', function (): void {
    LocalizedPage::query()->whereSlug('x', locale: "en') or 1=1 --")->get();
})->throws(InvalidLocaleException::class);

it('refuses a hostile column in scopes', function (): void {
    Article::query()->whereSlug('x', column: 'slug; drop table articles')->get();
})->throws(InvalidSlugDefinitionException::class);

it('bounds a five megabyte source', function (): void {
    $slug = Slugs::generate(new Article(['name' => str_repeat('word ', 1_000_000)]));

    expect(mb_strlen($slug))->toBeLessThanOrEqual(255);
});

it('rejects a separator that would inject a path segment', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->separator('/'));

    Article::query()->create(['name' => 'a b']);
})->throws(InvalidSlugDefinitionException::class);

it('rejects custom slugger output with URL syntax', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->using(fn (string $source): string => 'a/b?c'));

    Article::query()->create(['name' => 'x']);
})->throws(SlugGenerationException::class);

it('neutralises markup in manual slugs by default', function (): void {
    expect(Article::query()->create(['slug' => '<script>alert(1)</script>'])->slug)->toBe('scriptalert1script');
});

it('never reads a tenant id from the request unless opted in', function (): void {
    ScopedItem::query()->create(['name' => 'Secret Plan', 'tenant_id' => 666]);

    $probe = fn (array $data) => Validator::make($data, ['slug' => [UniqueSlug::for(ScopedItem::class)->where('tenant_id', 1)]])->passes();

    expect($probe(['slug' => 'secret-plan', 'tenant_id' => 666]))->toBeTrue();
});

it('issues no query for an over-long route value', function (): void {
    $count = 0;
    DB::listen(function () use (&$count): void {
        $count++;
    });

    expect((new Article)->resolveRouteBinding(str_repeat('a', 5000), 'slug'))->toBeNull()
        ->and($count)->toBe(0);
});

it('never names another row\'s value in exceptions', function (): void {
    // Every candidate is taken by another row: base, the one sequential probe, the one random one.
    config(['sluggable.limits.sequential_probes' => 1, 'sluggable.limits.random_attempts' => 1]);
    DB::table('articles')->insert([
        ['slug' => 'other-tenant-secret'],
        ['slug' => 'other-tenant-secret-2'],
        ['slug' => 'other-tenant-secret-qqqqqqqq'],
    ]);
    Str::createRandomStringsUsing(static fn (int $length): string => str_repeat('q', $length));

    $exception = null;

    try {
        Article::query()->create(['name' => 'Other Tenant Secret']);
    } catch (SlugGenerationException $caught) {
        $exception = $caught;
    } finally {
        Str::createRandomStringsNormally();
    }

    expect($exception)->toBeInstanceOf(SlugGenerationException::class)
        ->and((string) $exception?->getMessage())->toContain('[slug]')->not->toContain('other-tenant-secret');
});
