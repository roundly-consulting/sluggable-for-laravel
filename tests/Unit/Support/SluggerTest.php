<?php

declare(strict_types=1);

use RoundlyConsulting\Sluggable\DataTransferObjects\SlugFormat;
use RoundlyConsulting\Sluggable\Exceptions\SlugGenerationException;
use RoundlyConsulting\Sluggable\Support\Slugger;

it('keeps non-ascii letters in unicode mode', function (): void {
    $format = new SlugFormat(unicode: true);

    expect(Slugger::slugify('Žltý Kôň 日本', $format))->toBe('žltý-kôň-日本');
});

it('can keep case', function (): void {
    expect(Slugger::slugify('Hello World', new SlugFormat(lowercase: false)))->toBe('Hello-World');
});

it('strips zero-width and bidi marks', function (): void {
    expect(Slugger::slugify("pay\u{200B}pal\u{202E}", new SlugFormat))->toBe('paypal');
});

it('drops non-whitespace control characters but keeps whitespace separating words', function (): void {
    expect(Slugger::slugify("a\x00b\x07c d", new SlugFormat))->toBe('abc-d');
});

it('caps the number of words', function (): void {
    expect(Slugger::slugify('one two three four', new SlugFormat(maxWords: 2)))->toBe('one-two');
});

it('uses a custom slugger for the formatting steps and still caps words and length', function (): void {
    $format = new SlugFormat(maxLength: 10, maxWords: 3, slugger: static fn (string $source, ?string $locale): string => strtoupper(str_replace(' ', '-', $source)).($locale ?? ''));

    expect(Slugger::slugify('ab cd ef gh', $format, 'en', 'x'))->toBe('AB-CD-EF');
});

it('rejects a custom slugger that emits URL syntax', function (mixed $output): void {
    $format = new SlugFormat(slugger: static fn (): mixed => $output);

    Slugger::slugify('anything', $format);
})->with(['a/b?c', 'a b', 'a%20b', 42, "abc\n"])->throws(SlugGenerationException::class);

it('composes affixes and a collision suffix without cutting them', function (): void {
    $format = new SlugFormat(maxLength: 20);

    $value = Slugger::compose('a-very-long-body-that-overflows', $format, 'pre', 'suf', '123');

    expect($value)->toStartWith('pre-')->toEndWith('-suf-123')
        ->and(mb_strlen($value))->toBeLessThanOrEqual(20);
});

it('composes a bare body', function (): void {
    expect(Slugger::compose('body', new SlugFormat))->toBe('body')
        ->and(Slugger::compose('body', new SlugFormat, collision: '2'))->toBe('body-2');
});

it('slugifies affixes with the default pipeline', function (): void {
    expect(Slugger::affix('Pre Fix!', new SlugFormat))->toBe('pre-fix');
});

it('checks reserved words case-insensitively', function (): void {
    $format = new SlugFormat(reserved: ['create']);

    expect(Slugger::isReserved('Create', $format))->toBeTrue()
        ->and(Slugger::isReserved('created', $format))->toBeFalse()
        ->and(Slugger::isReserved('create', new SlugFormat))->toBeFalse();
});

it('recognises safe URL segments', function (string $value, bool $safe): void {
    expect(Slugger::isSafeSegment($value))->toBe($safe);
})->with([
    ['hello-world', true],
    ['Hello_World.1~', true],
    ['a b', false],
    ['a/b', false],
    ['a?b', false],
    ['a#b', false],
    ['a%b', false],
    ["a\x01b", false],
    ["a\u{200B}b", false],
]);

it('produces random slugs in the requested case', function (): void {
    expect(Slugger::random(12, true))->toMatch('/^[a-z0-9]{12}$/')
        ->and(Slugger::random(6, false))->toMatch('/^[A-Za-z0-9]{6}$/');
});

it('knows whether a value is already normalised', function (): void {
    expect(Slugger::isNormalized('hello-world', new SlugFormat))->toBeTrue()
        ->and(Slugger::isNormalized('Hello World', new SlugFormat))->toBeFalse();
});

it('bounds work on huge sources', function (): void {
    $slug = Slugger::slugify(str_repeat('a', 5_000_000), new SlugFormat);

    expect(mb_strlen($slug))->toBeLessThanOrEqual(255);
});

it('slugifies with every allowed separator, including dotted ones', function (string $separator, string $expected): void {
    $format = new SlugFormat(separator: $separator, maxLength: 12);

    expect(Slugger::slugify(' Hello World again ', $format))->toBe($expected)
        ->and(Slugger::slugify('Hello', new SlugFormat(separator: $separator, slugger: static fn (string $source): string => $separator.$source.$separator)))->toBe('Hello');
})->with([
    ['..', 'hello..world'],
    ['...', 'hello...worl'],
    ['-..', 'hello-..worl'],
    ['.~.', 'hello.~.worl'],
    ['_', 'hello_world'],
]);
