<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * What happens when the sources produce an empty slug.
 */
enum EmptySourcePolicy: string
{
    use Helpers;

    case Random = 'random';

    case Skip = 'skip';

    case Fail = 'fail';
}
