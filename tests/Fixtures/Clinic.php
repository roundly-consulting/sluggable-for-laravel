<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RoundlyConsulting\Sluggable\Concerns\HasSlug;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Definitions\SlugOptions;

class Clinic extends Model implements Sluggable
{
    use HasSlug;

    protected $table = 'clinics';

    protected $guarded = [];

    /** @return HasMany<ClinicProduct, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(ClinicProduct::class);
    }

    /** @return BelongsToMany<ClinicCategory, $this> */
    public function clinicCategories(): BelongsToMany
    {
        return $this->belongsToMany(ClinicCategory::class, 'clinic_clinic_category');
    }

    public function slugOptions(): SlugOptions
    {
        return SlugOptions::make(SlugDefinition::for('slug')->from('name')->routeKey());
    }
}
