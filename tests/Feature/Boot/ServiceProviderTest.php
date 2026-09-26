<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;
use RoundlyConsulting\Sluggable\Contracts\SlugLocales;
use RoundlyConsulting\Sluggable\SluggableServiceProvider;
use RoundlyConsulting\Sluggable\SlugManager;
use RoundlyConsulting\Sluggable\Support\ConfigSlugLocales;

it('binds the manager as a singleton and the config locale source by default', function (): void {
    expect(app(SlugManager::class))->toBe(app(SlugManager::class))
        ->and(app(SlugLocales::class))->toBeInstanceOf(ConfigSlugLocales::class);
});

it('registers the schema and rule macros', function (): void {
    expect(Blueprint::hasMacro('slug'))->toBeTrue()
        ->and(Blueprint::hasMacro('localizedSlug'))->toBeTrue()
        ->and(Blueprint::hasMacro('uniqueSlug'))->toBeTrue()
        ->and(Blueprint::hasMacro('morphKey'))->toBeTrue()
        ->and(Rule::hasMacro('uniqueSlug'))->toBeTrue()
        ->and(Rule::hasMacro('validSlug'))->toBeTrue();
});

it('registers the three commands', function (): void {
    $commands = array_keys(Artisan::all());

    expect($commands)->toContain('sluggable:regenerate', 'sluggable:indexes', 'sluggable:duplicates');
});

it('ships the migration publish-only, timestamped', function (): void {
    expect(SluggableServiceProvider::class)
        ->toNotAutoLoadMigrations()
        ->toPublishMigrationsTimestamped('sluggable-migrations', 1);
});

it('renders counts and switches in about, never locales or reserved words', function (): void {
    config([
        'sluggable.reserved' => ['zz-reserved-probe'],
        'sluggable.locales.supported' => ['en', 'qx-Leak42'],
    ]);

    expect('sluggable')->toLeakNoSecrets(
        secrets: ['zz-reserved-probe', 'qx-Leak42'],
        mustRender: ['Supported locales', '2 configured', 'History', 'Reserved slugs', '1 reserved', 'Collision retries', 'DEFAULT'],
    );
});

it('reports customised defaults in about', function (): void {
    config(['sluggable.defaults.separator' => '_', 'sluggable.history.enabled' => true]);

    expect('sluggable')->toLeakNoSecrets(secrets: [], mustRender: ['CUSTOM', 'ON']);
});
