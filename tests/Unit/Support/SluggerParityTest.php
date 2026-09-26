<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugFormat;
use RoundlyConsulting\Sluggable\Support\Slugger;
use RoundlyConsulting\Sluggable\Support\Truncator;

/**
 * The migration contract: with the default format, Slugger IS Str::slug() for every input whose
 * Str::slug() output fits maxLength and whose source fits max_source_length — so a package moving
 * onto sluggable keeps every slug it already has.
 */
dataset('parity corpus', [
    'Hello World', 'hello world', '  padded  ', 'Žltý kôň', 'Červená stolička', 'Straße', 'Привет мир',
    '日本語', 'C++ & C#', '@home', 'me@example.com', 'a_b-c d', '--leading and trailing--', 'multiple   spaces',
    'Émile Zola', 'naïve café', 'Ørsted', 'Łódź', 'İstanbul', 'Crème brûlée', 'São Paulo', 'Ελληνικά',
    '100% pure', 'Price: $5.00', 'x/y?z#w', "tab\tseparated", "new\nline", 'MiXeD CaSe', '123 456', '½ pint',
    'dots.and.dots', 'under_score', 'ümlaut über', 'ﬁnancial', '👍 emoji only', '👍', '###', '',
    'Tom & Jerry', 'Ålesund', 'Đorđe', 'Hamburg-Altona', 'rock\'n\'roll', '"quoted"', '(parens)',
]);

it('matches Str::slug byte for byte with the default format', function (string $source): void {
    expect(Slugger::slugify($source, new SlugFormat))->toBe(Str::slug($source));
})->with('parity corpus');

it('matches Str::slug with a language', function (string $source, string $language): void {
    expect(Slugger::slugify($source, new SlugFormat, $language))->toBe(Str::slug($source, '-', $language));
})->with([
    ['Straße Äpfel', 'de'],
    ['Привет мир', 'ru'],
    ['Žltý kôň', 'sk'],
]);

it('matches Str::slug with another separator', function (): void {
    $format = new SlugFormat(separator: '_');

    expect(Slugger::slugify('Hello World-Foo', $format))->toBe(Str::slug('Hello World-Foo', '_'));
});

it('diverges on purpose for inputs beyond the bounds (source capped, then truncated at a word)', function (): void {
    $source = str_repeat('lorem ipsum dolor sit amet ', 200);

    $expected = Truncator::truncate(Str::slug(mb_substr($source, 0, 2000)), 255, '-');

    expect(Slugger::slugify($source, new SlugFormat))->toBe($expected)
        ->and(mb_strlen($expected))->toBeLessThanOrEqual(255);
});
