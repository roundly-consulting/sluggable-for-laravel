<?php

declare(strict_types=1);

/**
 * Both directions: every key src/ (and database/) reads is shipped, every shipped leaf is read.
 *
 * SluggableConfig reads every key LITERALLY, but most of them through the toolkit's validating
 * accessors (`Config::using(...)->enum('sluggable.defaults.manual', …)`) rather than a bare
 * `config()` call, so `sluggable.` literals count as reads wherever they appear.
 */
it('ships exactly the config keys it reads', function (): void {
    expect(__DIR__.'/../config/sluggable.php')->toSatisfyConfigContract(__DIR__.'/../src', [
        'extraReadPrefixes' => ['sluggable.'],
        // The dictionary is a host-editable replacement MAP read wholesale; its keys are data.
        'allowUnread' => ['sluggable.defaults.dictionary.@'],
    ]);
});
