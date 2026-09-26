<?php

declare(strict_types=1);

use RoundlyConsulting\Sluggable\Support\Truncator;

it('leaves short values untouched', function (): void {
    expect(Truncator::truncate('short', 10, '-'))->toBe('short');
});

it('cuts at the last separator in the second half of the budget', function (): void {
    expect(Truncator::truncate('hello-world-again', 13, '-'))->toBe('hello-world');
});

it('keeps a cut that already lands on a boundary', function (): void {
    expect(Truncator::truncate('hello-world', 5, '-'))->toBe('hello');
});

it('hard-cuts when the only boundary is too early', function (): void {
    expect(Truncator::truncate('a-bcdefghijkl', 8, '-'))->toBe('a-bcdefg');
});

it('is multibyte safe', function (): void {
    expect(Truncator::truncate('žžžžžž', 3, '-'))->toBe('žžž');
});

it('returns nothing for a non-positive limit', function (): void {
    expect(Truncator::truncate('anything', 0, '-'))->toBe('');
});

it('trims a dotted separator without treating it as a character range', function (): void {
    expect(Truncator::truncate('..ab..cd..', 8, '..'))->toBe('ab..cd');
});
