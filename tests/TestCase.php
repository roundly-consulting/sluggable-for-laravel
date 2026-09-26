<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Tests;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Sluggable\SluggableServiceProvider;
use RoundlyConsulting\Sluggable\Support\SlugOptionsRegistry;
use RoundlyConsulting\Sluggable\Tests\Fixtures\Configurable;
use RoundlyConsulting\Testing\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    /** @return list<class-string<ServiceProvider>> */
    protected function packageProviders(): array
    {
        return [SluggableServiceProvider::class];
    }

    /**
     * The package's own (publish-only) history migration, by provider class, plus the host-owned
     * fixture tables this suite slugs.
     *
     * @return list<class-string<ServiceProvider>|string>
     */
    protected function migrationSources(): array
    {
        return [__DIR__.'/database/migrations', SluggableServiceProvider::class];
    }

    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return [
            'app.locale' => 'en',
            'app.fallback_locale' => 'en',
            'sluggable.locales.supported' => ['en', 'sk', 'de'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Configurable::reset();
        SlugOptionsRegistry::flush();
    }
}
