<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Suffixes;

use RoundlyConsulting\Sluggable\Contracts\SuffixGenerator;
use RoundlyConsulting\Sluggable\Definitions\ResolvedSlugDefinition;
use RoundlyConsulting\Sluggable\Support\Slugger;

/** A random `[a-z0-9]{n}` suffix (mixed case when the definition keeps case). */
final readonly class RandomSuffix implements SuffixGenerator
{
    public function __construct(
        private int $length = 8,
        private bool $lowercase = true,
    ) {}

    public function suffix(string $base, int $attempt, ResolvedSlugDefinition $definition): string
    {
        return Slugger::random($this->length, $this->lowercase);
    }
}
