<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Sluggable\Concerns\HasSlug;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Definitions\SlugOptions;
use RoundlyConsulting\Sluggable\Enums\UpdatePolicy;

class ClinicProduct extends Model implements Sluggable
{
    use HasSlug;
    use SoftDeletes;

    protected $table = 'clinic_products';

    protected $guarded = [];

    public function slugOptions(): SlugOptions
    {
        return Configurable::for(static::class) ?? SlugOptions::make(
            SlugDefinition::for('slug')->from('name')->uniqueWithin('clinic_id')->routeKey()
                ->onUpdate(UpdatePolicy::WhenSourceChanges)->keepHistory(),
        );
    }
}
