<?php

declare(strict_types=1);

use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\Support\IndexNames;

it('names indexes and generated columns deterministically', function (): void {
    expect(IndexNames::index('articles', 'slug'))->toBe('articles_slug_slug_unique')
        ->and(IndexNames::index('topics', 'slug', 'pt-BR'))->toBe('topics_slug_pt_br_slug_unique')
        ->and(IndexNames::localeColumn('slug', 'pt-BR'))->toBe('slug__pt_br')
        ->and(IndexNames::liveColumn('slug'))->toBe('slug__live');
});

it('shortens long names to 60 characters with a crc32 tail', function (): void {
    $name = IndexNames::index(str_repeat('very_long_table_', 4), 'slug', 'zh-Hant-TW');

    expect(strlen($name))->toBe(60)
        ->and($name)->toMatch('/_[0-9a-f]{8}$/')
        ->and(IndexNames::index(str_repeat('very_long_table_', 4), 'slug', 'zh-Hant-TW'))->toBe($name);
});

it('rejects locales that normalise to the same name', function (): void {
    IndexNames::assertDistinct(['en', 'pt-BR', 'pt_BR']);
})->throws(InvalidSlugDefinitionException::class);

it('accepts a distinct locale set, repeated entries included', function (): void {
    IndexNames::assertDistinct(['en', 'sk', 'en']);

    expect(true)->toBeTrue();
});
