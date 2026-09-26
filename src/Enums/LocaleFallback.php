<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * The locale chain used when reading a locale-map slug and when binding routes.
 */
enum LocaleFallback: string
{
    use Helpers;

    case None = 'none';

    case Fallback = 'fallback';

    case Any = 'any';
}
