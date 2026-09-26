<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\DataTransferObjects;

/**
 * One unique index a driver plans: its name, the DDL creating it, and (MySQL) the generated
 * column it needs first.
 */
final readonly class PlannedIndex
{
    /** @param list<string> $statements */
    public function __construct(
        public string $table,
        public string $name,
        public array $statements,
        public ?string $generatedColumn = null,
        public ?string $generatedColumnStatement = null,
    ) {}

    /** @return list<string> */
    public function ddl(): array
    {
        return $this->generatedColumnStatement === null
            ? $this->statements
            : [$this->generatedColumnStatement, ...$this->statements];
    }
}
