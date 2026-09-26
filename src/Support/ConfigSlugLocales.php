<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Support;

use RoundlyConsulting\Sluggable\Contracts\SlugLocales;

/**
 * The default SlugLocales source: `sluggable.locales.*`, falling back to the app locales.
 */
final class ConfigSlugLocales implements SlugLocales
{
    public function supported(): array
    {
        $configured = SluggableConfig::supportedLocales();

        if ($configured !== null && $configured !== []) {
            return array_values(array_unique($configured));
        }

        $locales = array_filter(
            [config('app.locale'), config('app.fallback_locale')],
            static fn (mixed $locale): bool => is_string($locale) && $locale !== '',
        );

        return array_values(array_unique($locales));
    }

    public function fallback(): ?string
    {
        $fallback = SluggableConfig::fallbackLocale() ?? config('app.fallback_locale');

        return is_string($fallback) && $fallback !== '' ? $fallback : null;
    }

    public function current(): string
    {
        return app()->getLocale();
    }
}
