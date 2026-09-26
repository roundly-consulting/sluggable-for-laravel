<?php

declare(strict_types=1);

use RoundlyConsulting\Sluggable\Models\SlugHistory;
use RoundlyConsulting\Testing\Arch\ArchPresets;

ArchPresets::strictTypes('RoundlyConsulting\Sluggable');

// SlugHistory is swappable via sluggable.history.model, so it must stay extendable (pinned below).
// The base exception is abstract and excluded automatically.
ArchPresets::finalByDefault('RoundlyConsulting\Sluggable', [SlugHistory::class]);
ArchPresets::swappableModelsAreNotFinal([SlugHistory::class => 'sluggable.history.model']);
ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\Sluggable');
ArchPresets::modelsResolveThroughSeam(__DIR__.'/../src', 'Support', ['sluggable.history.model']);
ArchPresets::morphColumnsUseTheSeam(__DIR__.'/../database/migrations');

// No alsoAllow: require holds php/ext/illuminate/roundly only.
ArchPresets::runtimeRequireIsWhitelisted(__DIR__.'/../composer.json');
ArchPresets::noDebuggingLeftovers();

arch('the pure pipeline stays free of the database layer')
    ->expect([
        'RoundlyConsulting\Sluggable\Support\Slugger',
        'RoundlyConsulting\Sluggable\Support\Truncator',
        'RoundlyConsulting\Sluggable\Support\IdentifierGuard',
        'RoundlyConsulting\Sluggable\Suffixes',
    ])
    ->not->toUse('Illuminate\Database');

arch('sluggable never references translatable (the DAG points translatable → sluggable)')
    ->expect('RoundlyConsulting\Sluggable')
    ->not->toUse('RoundlyConsulting\Translatable');
