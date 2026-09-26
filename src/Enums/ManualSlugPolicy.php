<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * How a slug supplied by the host (not generated) is treated.
 */
enum ManualSlugPolicy: string
{
    use Helpers;

    case Normalize = 'normalize';

    case Verbatim = 'verbatim';

    case Strict = 'strict';
}
