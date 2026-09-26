<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Sluggable\Concerns\HasSlug;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Definitions\SlugOptions;

class PlainThing extends Model implements Sluggable
{
    use HasSlug;

    protected $table = 'plain_things';

    protected $guarded = [];

    public function slugOptions(): SlugOptions
    {
        return Configurable::for(static::class) ?? SlugOptions::make(SlugDefinition::for('slug')->from('name'));
    }
}
