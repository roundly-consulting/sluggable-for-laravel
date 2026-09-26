<?php

declare(strict_types=1);

use RoundlyConsulting\Sluggable\Exceptions\InvalidLocaleException;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\Support\IdentifierGuard;

it('accepts real locale tags', function (string $locale): void {
    expect(IdentifierGuard::locale($locale))->toBe($locale);
})->with(['en', 'sk', 'pt-BR', 'pt_BR', 'zh-Hant-TW', 'qx-Leak42']);

it('rejects hostile or malformed locales', function (mixed $locale): void {
    IdentifierGuard::locale($locale);
})->with([
    "en'; drop table x; --",
    'EN',
    'e',
    'en-',
    'en->sk',
    'en"',
    "en\n",
    42,
    null,
    'ab-'.str_repeat('abcdefgh-', 5).'x1',
])->throws(InvalidLocaleException::class);

it('caps locales at 35 characters', function (): void {
    expect(IdentifierGuard::isLocale('abc-'.str_repeat('abcdefgh-', 2).'abcdefgh'))->toBeTrue()
        ->and(IdentifierGuard::isLocale('abc-'.str_repeat('abcdefgh-', 3).'abcdefgh'))->toBeFalse();
});

it('allowlists SQL identifiers', function (): void {
    expect(IdentifierGuard::identifier('slug_2'))->toBe('slug_2')
        ->and(IdentifierGuard::qualifiedIdentifier('products.slug'))->toBe('products.slug')
        ->and(IdentifierGuard::isIdentifier('slug; drop'))->toBeFalse();
});

it('rejects hostile identifiers', function (string $identifier): void {
    IdentifierGuard::qualifiedIdentifier($identifier);
})->with(['slug"', 'a b', 'products.slug.x', '1slug', '', "slug\n", "products.slug\n"])->throws(InvalidSlugDefinitionException::class);
