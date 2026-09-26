<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Sluggable\Concerns\HasSlug;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Definitions\SlugOptions;

/** A cast-based locale map (`array` cast) — the posts/shops/advertisements storage. */
class LocalizedPage extends Model implements Sluggable
{
    use HasSlug;
    use SoftDeletes;

    protected $table = 'localized_pages';

    protected $guarded = [];

    protected $casts = ['name' => 'array', 'slug' => 'array', 'published' => 'boolean'];

    public function slugOptions(): SlugOptions
    {
        return Configurable::for(static::class) ?? SlugOptions::make(
            SlugDefinition::for('slug')->from('name')->routeKey(),
        );
    }
}
