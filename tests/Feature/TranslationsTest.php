<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

dataset('translation files', ['validation']);

/**
 * Every language ships the same keys and the same `:placeholders` as English.
 */
it('ships the same keys in every language', function (string $file): void {
    $en = Arr::dot(require __DIR__.'/../../resources/lang/en/'.$file.'.php');
    $sk = Arr::dot(require __DIR__.'/../../resources/lang/sk/'.$file.'.php');

    expect($en)->not->toBeEmpty()
        ->and(array_keys($sk))->toBe(array_keys($en));
})->with('translation files');

it('keeps every placeholder in every language', function (string $file): void {
    $en = Arr::dot(require __DIR__.'/../../resources/lang/en/'.$file.'.php');
    $sk = Arr::dot(require __DIR__.'/../../resources/lang/sk/'.$file.'.php');

    $placeholders = static function (mixed $line): array {
        preg_match_all('/:(\w+)/', (string) $line, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    };

    foreach ($en as $key => $line) {
        expect($placeholders($sk[$key] ?? ''))->toBe($placeholders($line), "{$file}.{$key}");
    }
})->with('translation files');

it('loads slovak and english under the sluggable namespace', function (): void {
    expect(trans('sluggable::validation.reserved', ['attribute' => 'slug']))
        ->toBe('The slug is reserved and cannot be used.');

    app()->setLocale('sk');

    expect(trans('sluggable::validation.reserved', ['attribute' => 'slug']))
        ->toBe('Hodnota poľa slug je rezervovaná a nemožno ju použiť.');
});
