<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Support;

use RoundlyConsulting\Sluggable\DataTransferObjects\SlugSeed;
use RoundlyConsulting\Sluggable\Definitions\ResolvedSlugOptions;
use RoundlyConsulting\Sluggable\Enums\ChangeReason;

/**
 * Per-model-instance bookkeeping for one save: what sluggable wrote (so its own writes are never
 * mistaken for manual input), why, and from which seed.
 */
final class SlugStateEntry
{
    public ?ResolvedSlugOptions $options = null;

    /** @var array<string, array<string, string>> column => locale key => value */
    public array $written = [];

    /** @var array<string, array<string, ChangeReason>> */
    public array $reasons = [];

    /** @var array<string, array<string, SlugSeed>> */
    public array $seeds = [];

    /** A collision retry is in flight: the re-fired creating/updating must not regenerate. */
    public bool $retrying = false;

    /** Record history for this save even when the definition does not keep it (regenerate --history). */
    public bool $forceHistory = false;

    /** Suppress SlugChanged for this save (regenerate --without-events). */
    public bool $silent = false;
}
