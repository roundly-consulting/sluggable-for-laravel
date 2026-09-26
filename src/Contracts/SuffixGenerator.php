<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Contracts;

use RoundlyConsulting\Sluggable\Definitions\ResolvedSlugDefinition;

/**
 * A custom collision-suffix strategy (`SlugDefinition::suffixUsing()`).
 */
interface SuffixGenerator
{
    /** The suffix (WITHOUT separator) for the given 1-based collision attempt. */
    public function suffix(string $base, int $attempt, ResolvedSlugDefinition $definition): string;
}
