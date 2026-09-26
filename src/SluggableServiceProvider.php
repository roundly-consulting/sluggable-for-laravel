<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable;

use RoundlyConsulting\PackageToolkit\Concerns\RegistersBlueprintMacros;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;
use RoundlyConsulting\Sluggable\Commands\FindDuplicateSlugsCommand;
use RoundlyConsulting\Sluggable\Commands\RegenerateSlugsCommand;
use RoundlyConsulting\Sluggable\Commands\SlugIndexesCommand;
use RoundlyConsulting\Sluggable\Contracts\SlugLocales;
use RoundlyConsulting\Sluggable\Rules\RuleMacros;
use RoundlyConsulting\Sluggable\Schema\BlueprintMacros;
use RoundlyConsulting\Sluggable\Support\ConfigSlugLocales;
use RoundlyConsulting\Sluggable\Support\SluggableConfig;
use RoundlyConsulting\Sluggable\Support\SlugState;

final class SluggableServiceProvider extends PackageServiceProvider
{
    use RegistersBlueprintMacros;

    public function configurePackage(Package $package): void
    {
        $package
            ->name('sluggable')
            ->hasConfigFile()
            ->hasMigrations()
            ->hasTranslations()
            ->hasCommands([
                RegenerateSlugsCommand::class,
                SlugIndexesCommand::class,
                FindDuplicateSlugsCommand::class,
            ])
            ->contributesToAbout($this->aboutSection(...));
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(SlugManager::class);
        $this->app->singleton(SlugState::class);

        // bindIf: translatable-for-laravel (or the host) binds its own locale source and wins.
        $this->app->bindIf(SlugLocales::class, ConfigSlugLocales::class);

        // Register-time: migrations may run before providers boot.
        BlueprintMacros::register();
    }

    public function boot(): void
    {
        parent::boot();

        // The toolkit's morphKey()/auditable() macros used by the history migration.
        $this->registerBlueprintMacros();

        RuleMacros::register();
    }

    /**
     * Counts and switches only — the locale list and reserved words describe the host's market
     * and routes, so they never render verbatim.
     *
     * @return array<string, string>
     */
    private function aboutSection(): array
    {
        $locales = $this->app->make(SlugLocales::class);
        $reserved = count(SluggableConfig::reserved());

        return [
            'Supported locales' => count($locales->supported()).' configured',
            'Locale source' => class_basename($locales),
            'History' => SluggableConfig::historyEnabled() ? 'ON' : 'OFF',
            'History redirect' => SluggableConfig::historyRedirect() ? 'ON' : 'OFF',
            'Reserved slugs' => $reserved === 0 ? 'NONE' : $reserved.' reserved',
            'Collision retries' => (string) SluggableConfig::retries(),
            'Key type' => SluggableConfig::keyType()->value,
            'Defaults' => SluggableConfig::defaultsAreCustomised() ? 'CUSTOM' : 'DEFAULT',
        ];
    }
}
