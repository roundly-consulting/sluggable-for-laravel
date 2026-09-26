<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * How a slug column stores its value: a plain string, or a json/jsonb locale map.
 */
enum SlugStorage: string
{
    use Helpers;

    case String = 'string';

    case LocaleMap = 'locale_map';
}
