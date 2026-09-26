<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Sluggable\Concerns\HasSlug;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Definitions\SlugOptions;

class ClinicCategory extends Model implements Sluggable
{
    use HasSlug;

    protected $table = 'clinic_categories';

    protected $guarded = [];

    protected $casts = ['name' => 'array', 'slug' => 'array'];

    public function slugOptions(): SlugOptions
    {
        return SlugOptions::make(SlugDefinition::for('slug')->from('name')->routeKey());
    }
}
