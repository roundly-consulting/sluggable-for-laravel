<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * What started a generation pass.
 */
enum GenerationTrigger: string
{
    use Helpers;

    case Creating = 'creating';

    case Updating = 'updating';

    case Restoring = 'restoring';

    case Manual = 'manual';

    case Command = 'command';
}
