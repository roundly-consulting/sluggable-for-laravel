<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Sluggable\Concerns\HasSlug;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;

/** Zero configuration: `slug` from `name` (the config defaults). */
class DefaultThing extends Model implements Sluggable
{
    use HasSlug;

    protected $table = 'plain_things';

    protected $guarded = [];
}
