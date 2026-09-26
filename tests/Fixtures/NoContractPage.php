<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Sluggable\Concerns\HasSlug;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Definitions\SlugOptions;

/** Uses a translation trait's API (isLocaleMapAttribute) but forgets the contract. */
class NoContractPage extends Model implements Sluggable
{
    use HasSlug;

    protected $table = 'contract_pages';

    protected $guarded = [];

    public function isLocaleMapAttribute(string $key): bool
    {
        return in_array($key, ['name', 'slug'], true);
    }

    public function slugOptions(): SlugOptions
    {
        return SlugOptions::make(SlugDefinition::for('slug')->from('name'));
    }
}
