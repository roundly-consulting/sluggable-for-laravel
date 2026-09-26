<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;

/**
 * Everything a uniqueness probe needs besides the candidates: which table (via the model), which
 * row to ignore, which scope, and which values this same save already claimed.
 */
final readonly class ProbeContext
{
    /**
     * @param  array<string, mixed>  $scope  scope column => value
     * @param  list<string>  $claimed
     * @param  list<string>  $locales  locales an across-locales probe must cover
     */
    public function __construct(
        public Model $model,
        public mixed $ignoreKey = null,
        public array $scope = [],
        public ?Model $scopeModel = null,
        public array $claimed = [],
        public array $locales = [],
        public bool $locking = false,
    ) {}

    /**
     * The same probe as a locking read: under MySQL's REPEATABLE READ a plain read inside an
     * outer transaction keeps seeing its old snapshot and would miss the row a concurrent writer
     * just committed; a locking read sees the latest committed version.
     */
    public function locking(): self
    {
        return new self($this->model, $this->ignoreKey, $this->scope, $this->scopeModel, $this->claimed, $this->locales, true);
    }
}
