<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\DataTransferObjects;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Find the model a retired slug belonged to — through the caller's own query (global scopes, the
 * parent relation of a scoped binding, trashed handling), so a retired slug never reveals a row
 * that query would not return.
 */
final readonly class HistoryLookup
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  string|null  $locale  null = any locale (locale-map columns); ignored for string columns
     * @param  Builder<*>|Relation<*, *, *>  $scopeQuery
     */
    public function __construct(
        public string $modelClass,
        public string $slug,
        public string $column,
        public ?string $locale,
        public Builder|Relation $scopeQuery,
    ) {}
}
