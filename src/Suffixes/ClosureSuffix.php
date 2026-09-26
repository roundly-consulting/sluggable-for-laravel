<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Suffixes;

use Closure;
use RoundlyConsulting\Sluggable\Contracts\SuffixGenerator;
use RoundlyConsulting\Sluggable\Definitions\ResolvedSlugDefinition;

/** Adapts a `Closure(string $base, int $attempt, ResolvedSlugDefinition $definition): string`. */
final readonly class ClosureSuffix implements SuffixGenerator
{
    public function __construct(
        private Closure $generator,
    ) {}

    public function suffix(string $base, int $attempt, ResolvedSlugDefinition $definition): string
    {
        $suffix = ($this->generator)($base, $attempt, $definition);

        return is_scalar($suffix) ? (string) $suffix : '';
    }
}
