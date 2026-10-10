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

// HasSlug reaches behaviour through SlugManager (recompute, model()->findInHistory), never an action.
ArchPresets::modelsGoThroughTheFacade('RoundlyConsulting\Sluggable');

// One case per subject: Pest's `->not->toUse()` over a multi-element `expect([...])` fails
// only when EVERY subject uses the target, so one database-bound stage would slip through.
foreach (['Support\Slugger', 'Support\Truncator', 'Support\IdentifierGuard', 'Suffixes'] as $stage) {
    arch("the pure pipeline stage {$stage} stays free of the database layer")
        ->expect("RoundlyConsulting\\Sluggable\\{$stage}")
        ->not->toUse(['Illuminate\Database', 'Illuminate\Support\Facades\DB']);
}

arch('sluggable never references translatable (the DAG points translatable → sluggable)')
    ->expect('RoundlyConsulting\Sluggable')
    ->not->toUse('RoundlyConsulting\Translatable');
