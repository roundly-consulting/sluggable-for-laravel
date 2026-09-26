<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * Which locales of a locale-map slug get generated.
 */
enum TargetLocales: string
{
    use Helpers;

    case Source = 'source';

    case Supported = 'supported';

    case Current = 'current';
}
