<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * Which slugs a regeneration run rewrites.
 */
enum RegenerationMode: string
{
    use Helpers;

    case Missing = 'missing';

    case Stale = 'stale';

    case All = 'all';
}
