<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Sluggable\Enums\RegenerationMode;

/**
 * One regeneration run (`sluggable:regenerate`, or one queued chunk of it).
 */
final readonly class RegenerateSlugsData
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<string>  $columns  [] = every column
     * @param  list<string>  $locales  [] = each definition's target locales
     * @param  list<int|string>|null  $keys  restrict to these primary keys (queued chunks)
     */
    public function __construct(
        public string $modelClass,
        public array $columns = [],
        public array $locales = [],
        public RegenerationMode $mode = RegenerationMode::Missing,
        public int $chunk = 500,
        public bool $dryRun = false,
        public bool $withHistory = false,
        public bool $withoutEvents = false,
        public bool $force = false,
        public ?array $keys = null,
    ) {}

    /** @param list<int|string> $keys */
    public function forKeys(array $keys): self
    {
        return new self(
            $this->modelClass,
            $this->columns,
            $this->locales,
            $this->mode,
            $this->chunk,
            $this->dryRun,
            $this->withHistory,
            $this->withoutEvents,
            $this->force,
            $keys,
        );
    }
}
