<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\DataTransferObjects;

use Closure;

/**
 * One slug source: an attribute name, a relation dot path (`brand.name`), or a closure.
 */
final readonly class SourcePart
{
    public function __construct(
        public string|Closure $source,
        public bool $isRelationPath,
    ) {}

    /** A plain attribute of the model itself — the only kind whose dirtiness can be tracked. */
    public function isAttribute(): bool
    {
        return is_string($this->source) && ! $this->isRelationPath;
    }
}
