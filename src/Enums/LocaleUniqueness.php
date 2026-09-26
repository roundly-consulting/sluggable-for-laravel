<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * Whether a locale-map slug is unique per locale, or across every locale of the map.
 */
enum LocaleUniqueness: string
{
    use Helpers;

    case PerLocale = 'per_locale';

    case AcrossLocales = 'across_locales';
}
