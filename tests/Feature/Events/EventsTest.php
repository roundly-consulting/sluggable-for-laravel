<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Sluggable\Enums\ChangeReason;
use RoundlyConsulting\Sluggable\Events\SlugChanged;
use RoundlyConsulting\Sluggable\Facades\Slugs;
use RoundlyConsulting\Sluggable\Tests\Fixtures\Article;
use RoundlyConsulting\Sluggable\Tests\Fixtures\LocalizedPage;

it('announces generated and manual changes', function (): void {
    Event::fake([SlugChanged::class]);

    $article = Article::query()->create(['name' => 'Evented']);
    $article->update(['slug' => 'hand-made']);

    Event::assertDispatched(SlugChanged::class, fn (SlugChanged $event): bool => $event->previous === null
        && $event->current === 'evented' && $event->reason === ChangeReason::Generated && $event->model->is($article));
    Event::assertDispatched(SlugChanged::class, fn (SlugChanged $event): bool => $event->previous === 'evented'
        && $event->current === 'hand-made' && $event->reason === ChangeReason::Manual);
});

it('announces one event per changed locale', function (): void {
    Event::fake([SlugChanged::class]);

    LocalizedPage::query()->create(['name' => ['en' => 'One', 'sk' => 'Jeden']]);

    Event::assertDispatchedTimes(SlugChanged::class, 2);
});

it('dispatches after commit only', function (): void {
    $seen = [];
    Event::listen(SlugChanged::class, function (SlugChanged $event) use (&$seen): void {
        $seen[] = $event->current;
    });

    try {
        DB::transaction(function () use (&$seen): void {
            Article::query()->create(['name' => 'Rolled Back']);
            expect($seen)->toBe([]);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    DB::transaction(fn () => Article::query()->create(['name' => 'Committed']));

    expect($seen)->toBe(['committed']);
});

it('stays silent inside withoutGeneration', function (): void {
    Event::fake([SlugChanged::class]);

    Slugs::withoutGeneration(fn () => Article::query()->create(['name' => 'Quiet', 'slug' => 'quiet']));

    Event::assertNotDispatched(SlugChanged::class);
});
