<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Suffixes;

use RoundlyConsulting\Sluggable\Contracts\SuffixGenerator;
use RoundlyConsulting\Sluggable\Definitions\ResolvedSlugDefinition;

/** `-2`, `-3`, … (the first number is configurable). */
final readonly class SequentialSuffix implements SuffixGenerator
{
    public function __construct(
        private int $start = 2,
    ) {}

    public function suffix(string $base, int $attempt, ResolvedSlugDefinition $definition): string
    {
        return (string) ($this->start + $attempt - 1);
    }
}
