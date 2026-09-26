<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Tests\Fixtures;

use RoundlyConsulting\Sluggable\Models\SlugHistory;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

class CustomSlugHistory extends SlugHistory
{
    use CountsCreations;
}
