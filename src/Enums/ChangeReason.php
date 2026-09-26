<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * Why a slug value changed.
 */
enum ChangeReason: string
{
    use Helpers;

    case Generated = 'generated';

    case Regenerated = 'regenerated';

    case Manual = 'manual';

    case CollisionRetry = 'collision_retry';

    case Restored = 'restored';
}
