<?php

declare(strict_types=1);

/**
 * Both directions: every key src/ (and database/) reads is shipped, every shipped leaf is read.
 *
 * SluggableConfig reads every key LITERALLY, most of them through the toolkit's validating
 * accessors (`Config::using(...)->enum('sluggable.defaults.manual', …)`), which the contract
 * reads natively — so no `extraReadPrefixes` is needed.
 */
it('ships exactly the config keys it reads', function (): void {
    expect(__DIR__.'/../config/sluggable.php')->toSatisfyConfigContract(__DIR__.'/../src', [
        // The dictionary is a host-editable replacement MAP read wholesale; its keys are data.
        'allowUnread' => ['sluggable.defaults.dictionary.@'],
    ]);
});
