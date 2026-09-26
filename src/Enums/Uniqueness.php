<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * Which rows a slug must be unique among.
 */
enum Uniqueness: string
{
    use Helpers;

    case None = 'none';

    case Global = 'global';

    case Scoped = 'scoped';
}
