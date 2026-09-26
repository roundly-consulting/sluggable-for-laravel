<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Events;

/**
 * The database rejected a slug another writer took concurrently and the save is being retried
 * with a fresh value — a metric worth watching.
 */
final readonly class SlugCollisionRetried
{
    /** @param class-string $modelClass */
    public function __construct(
        public string $modelClass,
        public string $column,
        public int $attempt,
    ) {}
}
