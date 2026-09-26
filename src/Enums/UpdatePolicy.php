<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * When an existing model's slug is (re)generated on update.
 */
enum UpdatePolicy: string
{
    use Helpers;

    case Never = 'never';

    case IfEmpty = 'if_empty';

    case WhenSourceChanges = 'when_source_changes';

    case Always = 'always';
}
