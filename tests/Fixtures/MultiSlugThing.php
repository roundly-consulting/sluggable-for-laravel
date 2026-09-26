<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RoundlyConsulting\Sluggable\Concerns\HasSlug;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Definitions\SlugOptions;

class MultiSlugThing extends Model implements Sluggable
{
    use HasSlug;

    protected $table = 'multi_slug_things';

    protected $guarded = [];

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function slugOptions(): SlugOptions
    {
        return Configurable::for(static::class) ?? SlugOptions::make(
            SlugDefinition::for('slug')->from('name')->routeKey(),
            SlugDefinition::for('handle')->from(['brand.name', 'name'])->separator('_')->maxLength(20)->immutable(),
            SlugDefinition::for('code')->from(fn (MultiSlugThing $thing): string => 'X '.$thing->sku)->lowercase(false)->notUnique(),
        );
    }
}
