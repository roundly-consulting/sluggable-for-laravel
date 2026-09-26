<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Sluggable\Attributes\Slug;
use RoundlyConsulting\Sluggable\Concerns\HasSlug;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\Enums\UpdatePolicy;

#[Slug('slug', from: 'name', uniqueWithin: ['tenant_id'], routeKey: true, onUpdate: UpdatePolicy::WhenSourceChanges, maxLength: 40)]
class AttributeItem extends Model implements Sluggable
{
    use HasSlug;

    protected $table = 'scoped_items';

    protected $guarded = [];
}
