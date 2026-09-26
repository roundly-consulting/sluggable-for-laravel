<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Enums\ManualSlugPolicy;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\Rules\UniqueSlug;
use RoundlyConsulting\Sluggable\Rules\ValidSlug;
use RoundlyConsulting\Sluggable\Tests\Fixtures\Article;
use RoundlyConsulting\Sluggable\Tests\Fixtures\LocalizedPage;
use RoundlyConsulting\Sluggable\Tests\Fixtures\ScopedItem;

function passes(array $data, array $rules): bool
{
    return Validator::make($data, $rules)->passes();
}

it('fails a taken string slug and ignores the edited model', function (): void {
    $article = Article::query()->create(['name' => 'Taken']);

    expect(passes(['slug' => 'taken'], ['slug' => [UniqueSlug::for(Article::class)]]))->toBeFalse()
        ->and(passes(['slug' => 'Taken!'], ['slug' => [UniqueSlug::for(Article::class)]]))->toBeFalse()
        ->and(passes(['slug' => 'taken'], ['slug' => [UniqueSlug::for(Article::class)->ignore($article)]]))->toBeTrue()
        ->and(passes(['slug' => 'taken'], ['slug' => [UniqueSlug::for(Article::class)->ignore($article->id)]]))->toBeTrue()
        ->and(passes(['slug' => 'free'], ['slug' => [UniqueSlug::for(Article::class)]]))->toBeTrue()
        ->and(passes(['slug' => ['not', 'a string']], ['slug' => [UniqueSlug::for(Article::class)]]))->toBeTrue();
});

it('compares verbatim when the manual policy keeps bytes', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->manual(ManualSlugPolicy::Verbatim));
    Article::query()->create(['slug' => 'Exact']);

    expect(passes(['slug' => 'exact'], ['slug' => [UniqueSlug::for(Article::class)]]))->toBeTrue()
        ->and(passes(['slug' => 'Exact'], ['slug' => [UniqueSlug::for(Article::class)]]))->toBeFalse();
});

it('checks every locale of a locale-map input and reports per locale', function (): void {
    LocalizedPage::query()->create(['name' => ['en' => 'Chair', 'sk' => 'Stolička']]);

    $validator = Validator::make(
        ['slug' => ['en' => 'chair', 'sk' => 'kreslo', 'xx' => 'chair', 'de' => '', 5 => 'x']],
        ['slug' => [UniqueSlug::for(LocalizedPage::class)]],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toBe(['slug.en'])
        ->and($validator->errors()->first('slug.en'))->toContain('(en)');
});

it('treats a string locale-map input as the current or given locale', function (): void {
    LocalizedPage::query()->create(['name' => ['sk' => 'Stolička']]);

    expect(passes(['slug' => 'stolicka'], ['slug' => [UniqueSlug::for(LocalizedPage::class)]]))->toBeTrue()
        ->and(passes(['slug' => 'stolicka'], ['slug' => [UniqueSlug::for(LocalizedPage::class)->locale('sk')]]))->toBeFalse()
        ->and(passes(['slug' => ['en' => 'stolicka', 'sk' => 'stolicka']], ['slug' => [Rule::uniqueSlug(LocalizedPage::class, 'slug')->locale('en')]]))->toBeTrue();
});

it('resolves scope values explicitly, never implicitly from the request', function (): void {
    ScopedItem::query()->create(['name' => 'Vaccines', 'tenant_id' => 1]);

    $rule = fn () => UniqueSlug::for(ScopedItem::class);

    expect(passes(['slug' => 'vaccines'], ['slug' => [$rule()->where('tenant_id', 1)]]))->toBeFalse()
        ->and(passes(['slug' => 'vaccines'], ['slug' => [$rule()->where('tenant_id', 2)]]))->toBeTrue()
        ->and(passes(['slug' => 'vaccines'], ['slug' => [$rule()->within(new ScopedItem(['tenant_id' => 1]))]]))->toBeFalse()
        ->and(passes(['slug' => 'vaccines', 'tenant_id' => 1], ['slug' => [$rule()->scopeFromInput('tenant_id')]]))->toBeFalse()
        ->and(passes(['slug' => 'vaccines', 'tenant_id' => 2], ['slug' => [$rule()->scopeFromInput('tenant_id')]]))->toBeTrue();

    $existing = ScopedItem::query()->where('tenant_id', 1)->firstOrFail();
    expect(passes(['slug' => 'vaccines'], ['slug' => [$rule()->ignore($existing)]]))->toBeTrue();
});

it('refuses to guess a scope value', function (): void {
    Validator::make(['slug' => 'x', 'tenant_id' => 1], ['slug' => [UniqueSlug::for(ScopedItem::class)]])->passes();
})->throws(InvalidSlugDefinitionException::class, 'tenant_id');

it('needs a model for a closure scope', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->uniqueWhere(fn (Builder $query, Article $article) => $query->where('tenant_id', $article->tenant_id)));

    expect(passes(['slug' => 'x'], ['slug' => [UniqueSlug::for(Article::class)->within(new Article(['tenant_id' => 1]))]]))->toBeTrue()
        ->and(fn () => passes(['slug' => 'x'], ['slug' => [UniqueSlug::for(Article::class)]]))->toThrow(InvalidSlugDefinitionException::class);
});

it('validates slug format, length and reserved words', function (): void {
    config(['sluggable.reserved' => ['admin']]);

    $rule = fn () => new ValidSlug(maxLength: 10);

    expect(passes(['slug' => 'good-slug'], ['slug' => [$rule()]]))->toBeTrue()
        ->and(passes(['slug' => 'Bad Slug'], ['slug' => [$rule()]]))->toBeFalse()
        ->and(passes(['slug' => 'far-too-long-slug'], ['slug' => [$rule()]]))->toBeFalse()
        ->and(passes(['slug' => 'admin'], ['slug' => [$rule()]]))->toBeFalse()
        ->and(passes(['slug' => ['en' => 'ok', 'sk' => 'NOT OK']], ['slug' => [$rule()]]))->toBeFalse()
        ->and(passes(['slug' => ['en' => 'ok', 'sk' => 5]], ['slug' => [$rule()]]))->toBeTrue();
});

it('uses a model definition\'s format', function (): void {
    definitionFor(Article::class, SlugDefinition::for('slug')->from('name')->separator('_')->lowercase(false)->reserved(['edit']));

    expect(passes(['slug' => 'Hello_World'], ['slug' => [ValidSlug::for(Article::class)]]))->toBeTrue()
        ->and(passes(['slug' => 'hello-world'], ['slug' => [Rule::validSlug(Article::class)]]))->toBeFalse()
        ->and(passes(['slug' => 'edit'], ['slug' => [ValidSlug::for(Article::class)]]))->toBeFalse();
});

it('translates messages', function (): void {
    $validator = Validator::make(['slug' => 'Bad Slug'], ['slug' => [new ValidSlug]]);

    expect($validator->errors()->first('slug'))->toContain('valid slug');

    Article::query()->create(['name' => 'Dup']);
    expect(Validator::make(['slug' => 'dup'], ['slug' => [UniqueSlug::for(Article::class)]])->errors()->first('slug'))
        ->toBe('The slug has already been taken.');
});

it('refuses a macro target that is not a model', function (): void {
    Rule::uniqueSlug(stdClass::class);
})->throws(InvalidSlugDefinitionException::class);
