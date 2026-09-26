<?php

declare(strict_types=1);

use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\Support\SlugHistoryModel;
use RoundlyConsulting\Sluggable\Tests\Fixtures\Article;
use RoundlyConsulting\Sluggable\Tests\Fixtures\CustomSlugHistory;

it('records and resolves history through the swapped model', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->regenerateOnUpdate()->keepHistory());

    expect('sluggable.history.model')->toHonourModelSwap(CustomSlugHistory::class, function (): array {
        $article = Article::query()->create(['name' => 'Swap Old']);
        $article->update(['name' => 'Swap New']);

        return SlugHistoryModel::query()->get()->all();
    });
});

it('refuses a history model that is not a SlugHistory', function (): void {
    config(['sluggable.history.model' => Article::class]);

    SlugHistoryModel::class();
})->throws(InvalidSlugDefinitionException::class);
