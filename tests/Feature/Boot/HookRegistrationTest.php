<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Sluggable\Concerns\HasSlug;
use RoundlyConsulting\Sluggable\Contracts\SlugLocales;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\Support\ConfigSlugLocales;
use RoundlyConsulting\Sluggable\Tests\Fixtures\PlainThing;

it('boots and saves a model without SoftDeletes', function (): void {
    $thing = PlainThing::query()->create(['name' => 'Plain']);
    $thing->update(['name' => 'Still Plain']);
    $thing->delete();

    expect($thing->slug)->toBe('plain');
});

it('refuses a HasSlug model that does not implement the contract', function (): void {
    $model = new class extends Model
    {
        use HasSlug;

        protected $table = 'plain_things';

        protected $guarded = [];
    };

    $model->newQuery()->create(['name' => 'x']);
})->throws(InvalidSlugDefinitionException::class, 'Sluggable contract');

it('reads locales from sluggable config, else the app locales', function (): void {
    $locales = new ConfigSlugLocales;

    expect($locales->supported())->toBe(['en', 'sk', 'de'])
        ->and($locales->fallback())->toBe('en')
        ->and($locales->current())->toBe('en');

    config(['sluggable.locales.supported' => null, 'app.locale' => 'sk', 'app.fallback_locale' => 'en', 'sluggable.locales.fallback' => 'de']);

    expect($locales->supported())->toBe(['sk', 'en'])
        ->and($locales->fallback())->toBe('de');
});

it('lets a host rebind the locale source', function (): void {
    app()->bind(SlugLocales::class, fn () => new class implements SlugLocales
    {
        public function supported(): array
        {
            return ['fr'];
        }

        public function fallback(): ?string
        {
            return null;
        }

        public function current(): string
        {
            return 'fr';
        }
    });

    expect(app(SlugLocales::class)->supported())->toBe(['fr']);
});
