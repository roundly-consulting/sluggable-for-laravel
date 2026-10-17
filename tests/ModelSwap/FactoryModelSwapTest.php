<?php

declare(strict_types=1);

use RoundlyConsulting\Sluggable\Models\SlugHistory;
use RoundlyConsulting\Sluggable\Tests\Fixtures\CustomSlugHistory;

/*
 * The factory names the packaged model in `$model`, so without a `modelName()` override
 * returning the seam it keeps building the packaged class after a host swapped it — and the
 * host's events, observers and casts never run on factory-made history rows.
 */
it('builds the configured history model from the factory', function (): void {
    expect(SlugHistory::factory()->create()::class)->toBe(CustomSlugHistory::class);
});
