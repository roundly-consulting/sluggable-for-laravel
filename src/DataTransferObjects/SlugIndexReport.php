<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\DataTransferObjects;

/**
 * Which slug indexes an ensure() run created, and which already existed.
 */
final readonly class SlugIndexReport
{
    /**
     * @param  list<string>  $created
     * @param  list<string>  $skipped
     * @param  list<string>  $statements  the DDL that ran (or would run, on a dry run)
     */
    public function __construct(
        public array $created = [],
        public array $skipped = [],
        public array $statements = [],
    ) {}

    public function merge(self $other): self
    {
        return new self(
            [...$this->created, ...$other->created],
            [...$this->skipped, ...$other->skipped],
            [...$this->statements, ...$other->statements],
        );
    }
}
