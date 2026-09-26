<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * How a colliding slug is made unique.
 */
enum SuffixStrategy: string
{
    use Helpers;

    case Sequential = 'sequential';

    case Random = 'random';

    case Custom = 'custom';
}
