<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;

/** Articles behind a `published` global scope (tenancy-style) — it must never hide a collision. */
class ScopedArticle extends Article
{
    protected static function booted(): void
    {
        static::addGlobalScope('published', static fn (Builder $query) => $query->where('published', true));
    }
}
