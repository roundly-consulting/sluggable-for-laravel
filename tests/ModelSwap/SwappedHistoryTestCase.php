<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Tests\ModelSwap;

use RoundlyConsulting\Sluggable\Tests\Fixtures\CustomSlugHistory;
use RoundlyConsulting\Sluggable\Tests\TestCase;

/** The history model swapped BEFORE boot, as a host would configure it. */
abstract class SwappedHistoryTestCase extends TestCase
{
    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return [...parent::configBeforeBoot(), 'sluggable.history.model' => CustomSlugHistory::class];
    }
}
