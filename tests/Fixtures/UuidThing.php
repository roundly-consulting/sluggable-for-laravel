<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Sluggable\Concerns\HasSlug;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Definitions\SlugOptions;

class UuidThing extends Model implements Sluggable
{
    use HasSlug, HasUuids {
        HasSlug::resolveRouteBindingQuery insteadof HasUuids;
    }

    protected $table = 'uuid_things';

    protected $guarded = [];

    public function slugOptions(): SlugOptions
    {
        return Configurable::for(static::class) ?? SlugOptions::make(SlugDefinition::for('slug')->from('name')->routeKey()->bindByKeyFallback());
    }
}
