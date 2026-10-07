<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Enums\EmptySourcePolicy;
use RoundlyConsulting\Sluggable\Enums\UpdatePolicy;
use RoundlyConsulting\Sluggable\Events\SlugChanged;
use RoundlyConsulting\Sluggable\Facades\Slugs;
use RoundlyConsulting\Sluggable\Tests\Fixtures\Article;
use RoundlyConsulting\Sluggable\Tests\Fixtures\ContractPage;
use RoundlyConsulting\Sluggable\Tests\Fixtures\LocalizedPage;
use RoundlyConsulting\Sluggable\Tests\Fixtures\PlainThing;

/*
 * A model loaded without its slug column (`select('id')`, a narrow API query) carries no current
 * slug to compare or merge with, so saving it must never generate one over the stored value.
 */

afterEach(function (): void {
    Model::preventAccessingMissingAttributes(false);
});

dataset('partial saves', [
    'touch() after select(id)' => [
        fn (): Model => PlainThing::query()->select('id')->firstOrFail(),
        fn (Model $model): bool => $model->touch(),
    ],
    'save() of a changed source after select(id, name)' => [
        fn (): Model => PlainThing::query()->select(['id', 'name'])->firstOrFail(),
        fn (Model $model): bool => $model->fill(['name' => 'Renamed'])->save(),
    ],
]);

it('keeps a stored string slug when a partially loaded model is saved', function (Closure $load, Closure $save): void {
    PlainThing::query()->create(['name' => 'First'])->update(['slug' => 'custom']);
    Event::fake([SlugChanged::class]);

    $save($load());

    expect(PlainThing::query()->sole()->slug)->toBe('custom');
    Event::assertNotDispatched(SlugChanged::class);
})->with('partial saves');

it('keeps the stored slug on the regenerate-on-update path when the slug column is not loaded', function (UpdatePolicy $policy): void {
    definitionFor(PlainThing::class, SlugDefinition::for('slug')->from('name')->onUpdate($policy));
    PlainThing::query()->create(['name' => 'First']);
    Event::fake([SlugChanged::class]);

    PlainThing::query()->select(['id', 'name'])->firstOrFail()->update(['name' => 'Second']);

    expect(PlainThing::query()->sole()->slug)->toBe('first');
    Event::assertNotDispatched(SlugChanged::class);

    // A fully loaded model still follows its source.
    PlainThing::query()->firstOrFail()->update(['name' => 'Third']);

    expect(PlainThing::query()->sole()->slug)->toBe('third');
})->with([UpdatePolicy::WhenSourceChanges, UpdatePolicy::Always]);

it('keeps a stored locale map when a partially loaded model is saved', function (): void {
    $page = LocalizedPage::query()->create(['name' => ['en' => 'Saving', 'sk' => 'Sporenie']]);
    $page->update(['slug' => ['en' => 'custom-en', 'sk' => 'vlastny-sk']]);
    definitionFor(LocalizedPage::class, SlugDefinition::for('slug')->from('name')->routeKey()->regenerateOnUpdate());
    Event::fake([SlugChanged::class]);

    LocalizedPage::query()->select('id')->firstOrFail()->touch();
    LocalizedPage::query()->select(['id', 'name'])->firstOrFail()->update(['name' => ['en' => 'Investing', 'sk' => 'Investovanie']]);

    expect(LocalizedPage::query()->sole()->slug)->toEqual(['en' => 'custom-en', 'sk' => 'vlastny-sk']);
    Event::assertNotDispatched(SlugChanged::class);
});

it('keeps a stored locale map behind the ProvidesLocaleMaps contract when a partially loaded model is saved', function (): void {
    $page = ContractPage::query()->create(['name' => ['en' => 'Saving', 'sk' => 'Sporenie']]);
    $page->update(['slug' => ['en' => 'custom-en', 'sk' => 'vlastny-sk']]);
    ContractPage::$writes = [];
    Event::fake([SlugChanged::class]);

    ContractPage::query()->select('id')->firstOrFail()->touch();
    ContractPage::query()->select(['id', 'name'])->firstOrFail()->update(['name' => ['en' => 'Investing', 'sk' => 'Investovanie']]);

    expect(ContractPage::query()->sole()->getAttributes()['slug'])->json()->toEqual(['en' => 'custom-en', 'sk' => 'vlastny-sk'])
        ->and(array_filter(ContractPage::$writes, static fn (array $write): bool => $write[0] === 'slug'))->toBe([]);
    Event::assertNotDispatched(SlugChanged::class);
});

it('keeps the stored slug when a partially loaded soft-deleted model is restored', function (): void {
    $article = Article::query()->create(['name' => 'First']);
    $article->update(['slug' => 'custom']);
    $article->delete();

    Article::onlyTrashed()->select('id')->firstOrFail()->restore();

    expect(Article::query()->sole()->slug)->toBe('custom');
});

it('sets nothing when explicit regeneration runs on a partially loaded model', function (): void {
    PlainThing::query()->create(['name' => 'First'])->update(['slug' => 'custom']);
    $partial = PlainThing::query()->select('id')->firstOrFail();

    expect(Slugs::apply($partial)->isEmpty())->toBeTrue()
        ->and($partial->regenerateSlugs()->getAttributes())->not->toHaveKey('slug');

    $partial->save();

    expect(PlainThing::query()->sole()->slug)->toBe('custom');
});

it('reads no unloaded slug column under preventAccessingMissingAttributes()', function (): void {
    PlainThing::query()->create(['name' => 'First'])->update(['slug' => 'custom']);
    LocalizedPage::query()->create(['name' => ['en' => 'Saving']]);
    Model::preventAccessingMissingAttributes();

    PlainThing::query()->select('id')->firstOrFail()->touch();
    LocalizedPage::query()->select('id')->firstOrFail()->touch();

    expect(PlainThing::query()->sole()->slug)->toBe('custom')
        ->and(LocalizedPage::query()->sole()->slug)->toEqual(['en' => 'saving']);
});

it('still fills the slug of a model created in this request without one', function (): void {
    definitionFor(PlainThing::class, SlugDefinition::for('slug')->from('name')->whenEmptySource(EmptySourcePolicy::Skip));

    $thing = PlainThing::query()->create([]);

    expect($thing->getAttributes())->not->toHaveKey('slug');

    $thing->update(['name' => 'Later']);

    expect(PlainThing::query()->sole()->slug)->toBe('later');
});
