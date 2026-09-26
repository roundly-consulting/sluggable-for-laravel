<?php

declare(strict_types=1);

use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Suffixes\ClosureSuffix;
use RoundlyConsulting\Sluggable\Suffixes\RandomSuffix;
use RoundlyConsulting\Sluggable\Suffixes\SequentialSuffix;
use RoundlyConsulting\Sluggable\Tests\Fixtures\Article;

beforeEach(function (): void {
    $this->definition = SlugDefinition::for('slug')->from('name')->resolve(new Article);
});

it('numbers sequential suffixes from the configured start', function (): void {
    expect((new SequentialSuffix(2))->suffix('x', 1, $this->definition))->toBe('2')
        ->and((new SequentialSuffix(2))->suffix('x', 3, $this->definition))->toBe('4')
        ->and((new SequentialSuffix(1))->suffix('x', 1, $this->definition))->toBe('1');
});

it('draws random suffixes of the configured length and case', function (): void {
    expect((new RandomSuffix(6))->suffix('x', 1, $this->definition))->toMatch('/^[a-z0-9]{6}$/')
        ->and((new RandomSuffix(10, false))->suffix('x', 1, $this->definition))->toMatch('/^[A-Za-z0-9]{10}$/');
});

it('passes base and attempt to a closure suffix', function (): void {
    $suffix = new ClosureSuffix(static fn (string $base, int $attempt): string => "{$base}v{$attempt}");

    expect($suffix->suffix('x', 3, $this->definition))->toBe('xv3')
        ->and((new ClosureSuffix(static fn (): array => []))->suffix('x', 1, $this->definition))->toBe('');
});
