<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugIndexSpec;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Exceptions\InvalidLocaleException;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\Exceptions\UnsupportedDriverException;
use RoundlyConsulting\Sluggable\Schema\SlugIndexes;
use RoundlyConsulting\Sluggable\Tests\Fixtures\LocalizedPage;
use RoundlyConsulting\Sluggable\Tests\Fixtures\ScopedItem;

function createTopics(): void
{
    Schema::create('topics', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('shop_id')->nullable();
        $table->localizedSlug('slug')->nullable();
        $table->slug('handle')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
}

it('creates the column types each engine maps the macros to', function (): void {
    createTopics();

    $columns = collect(Schema::getColumns('topics'))->keyBy('name');
    $jsonType = strtolower((string) $columns['slug']['type_name']);

    expect($jsonType)->toBe(match (DB::getDriverName()) {
        'pgsql' => 'jsonb',
        'mysql', 'mariadb' => 'json',
        default => 'text',
    })->and(strtolower((string) $columns['handle']['type_name']))->toBeIn(['varchar', 'character varying']);
});

it('creates per-locale indexes that reject duplicates, exempt missing locales and JSON null', function (): void {
    createTopics();

    $report = SlugIndexes::ensure(SlugIndexSpec::localeMap('topics', 'slug', locales: ['en', 'sk']));

    expect($report->created)->toBe(['topics_slug_en_slug_unique', 'topics_slug_sk_slug_unique']);

    DB::table('topics')->insert(['slug' => json_encode(['en' => 'chair'])]);
    DB::table('topics')->insert(['slug' => json_encode(['sk' => 'chair'])]);
    DB::table('topics')->insert(['slug' => json_encode(['en' => null])]);
    DB::table('topics')->insert(['slug' => json_encode(['en' => null])]);
    DB::table('topics')->insert(['slug' => null]);

    expect(fn () => DB::table('topics')->insert(['slug' => json_encode(['en' => 'chair'])]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('is idempotent', function (): void {
    createTopics();
    $spec = SlugIndexSpec::localeMap('topics', 'slug', scope: ['shop_id'], locales: ['en']);

    SlugIndexes::ensure($spec);
    $second = SlugIndexes::ensure($spec);

    expect($second->created)->toBe([])->and($second->skipped)->toBe(['topics_slug_en_slug_unique']);
});

it('scopes indexes', function (): void {
    createTopics();
    SlugIndexes::ensure(SlugIndexSpec::localeMap('topics', 'slug', scope: ['shop_id'], locales: ['en']));

    DB::table('topics')->insert(['shop_id' => 1, 'slug' => json_encode(['en' => 'chair'])]);
    DB::table('topics')->insert(['shop_id' => 2, 'slug' => json_encode(['en' => 'chair'])]);

    expect(fn () => DB::table('topics')->insert(['shop_id' => 1, 'slug' => json_encode(['en' => 'chair'])]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('exempts trashed rows with an exclude-trashed spec', function (string $storage): void {
    createTopics();

    $spec = $storage === 'map'
        ? SlugIndexSpec::localeMap('topics', 'slug', includeTrashed: false, locales: ['en'])
        : SlugIndexSpec::string('topics', 'handle', includeTrashed: false);
    SlugIndexes::ensure($spec);

    $row = fn (?string $deletedAt) => $storage === 'map'
        ? ['slug' => json_encode(['en' => 'x']), 'deleted_at' => $deletedAt]
        : ['handle' => 'x', 'deleted_at' => $deletedAt];

    DB::table('topics')->insert($row(now()->toDateTimeString()));
    DB::table('topics')->insert($row(now()->toDateTimeString()));
    DB::table('topics')->insert($row(null));

    expect(fn () => DB::table('topics')->insert($row(null)))->toThrow(UniqueConstraintViolationException::class);
})->with(['map', 'string']);

it('refuses an exclude-trashed spec on a table without the deleted-at column', function (): void {
    SlugIndexes::ensure(SlugIndexSpec::string('brands', 'name', includeTrashed: false));
})->throws(InvalidSlugDefinitionException::class, 'deleted-at');

it('plans DDL without executing it', function (): void {
    createTopics();

    $ddl = SlugIndexes::plan(SlugIndexSpec::localeMap('topics', 'slug', locales: ['en']));

    expect($ddl)->not->toBeEmpty()
        ->and(implode("\n", $ddl))->toContain('topics_slug_en_slug_unique')
        ->and(Schema::hasIndex('topics', 'topics_slug_en_slug_unique'))->toBeFalse();

    $dry = SlugIndexes::ensure(SlugIndexSpec::localeMap('topics', 'slug', locales: ['en']), dryRun: true);
    expect($dry->statements)->toBe($ddl)
        ->and(Schema::hasIndex('topics', 'topics_slug_en_slug_unique'))->toBeFalse();
});

it('emits the grammar\'s own JSON path so lookups can use the index', function (): void {
    createTopics();
    $ddl = implode("\n", SlugIndexes::plan(SlugIndexSpec::localeMap('topics', 'slug', locales: ['en'])));
    $grammar = DB::connection()->getQueryGrammar();

    if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
        expect($ddl)->toContain('invisible')->toContain('json_type(json_extract(`slug`, ')->toContain("= 'STRING'")->toContain('utf8mb4_bin');
    } else {
        expect($ddl)->toContain($grammar->wrap('slug->en'));
    }
});

it('derives specs from a model\'s definitions, skipping what no index can express', function (): void {
    definitionFor(LocalizedPage::class, SlugDefinition::for('slug')->from('name')->uniqueWithin('shop_id')->excludeTrashed());

    $specs = SlugIndexes::specsFor(LocalizedPage::class);

    expect($specs)->toHaveCount(1)
        ->and($specs[0]->scope)->toBe(['shop_id'])
        ->and($specs[0]->includeTrashed)->toBeFalse();

    definitionFor(LocalizedPage::class, SlugDefinition::for('slug')->from('name')->uniqueAcrossLocales());
    expect(SlugIndexes::specsFor(LocalizedPage::class))->toBe([]);

    definitionFor(ScopedItem::class, SlugDefinition::for('slug')->notUnique());
    expect(SlugIndexes::specsFor(ScopedItem::class, ['slug']))->toBe([])
        ->and(SlugIndexes::specsFor(ScopedItem::class, ['other']))->toBe([]);
});

it('ensures a model\'s indexes', function (): void {
    $report = SlugIndexes::forModel(LocalizedPage::class);

    expect($report->skipped)->toBe(['localized_pages_slug_en_slug_unique', 'localized_pages_slug_sk_slug_unique', 'localized_pages_slug_de_slug_unique']);
});

it('validates specs', function (Closure $spec): void {
    $spec();
})->with([
    fn () => SlugIndexSpec::string('topics; drop', 'slug'),
    fn () => SlugIndexSpec::string('topics', 'slug', scope: ['a b']),
])->throws(InvalidSlugDefinitionException::class);

it('rejects hostile locales in specs', function (): void {
    SlugIndexSpec::localeMap('topics', 'slug', locales: ["en')); drop table x; --"]);
})->throws(InvalidLocaleException::class);

it('rejects locales that collide once normalised', function (): void {
    createTopics();

    SlugIndexes::ensure(SlugIndexSpec::localeMap('topics', 'slug', locales: ['pt-BR', 'pt_BR']));
})->throws(InvalidSlugDefinitionException::class);

it('refuses unsupported drivers', function (): void {
    config(['database.connections.fake_sqlsrv' => ['driver' => 'sqlsrv', 'database' => 'x']]);

    SlugIndexes::plan(SlugIndexSpec::string('topics', 'slug', connection: 'fake_sqlsrv'));
})->throws(UnsupportedDriverException::class);

it('uses the index for lookups on postgres', function (): void {
    createTopics();
    SlugIndexes::ensure(SlugIndexSpec::localeMap('topics', 'slug', locales: ['en']));
    DB::statement('set enable_seqscan = off');

    $plan = collect(DB::select('explain '.DB::table('topics')->where('slug->en', 'x')->toRawSql()))
        ->map(fn (object $row): string => (string) array_values((array) $row)[0])->implode("\n");

    expect($plan)->toContain('topics_slug_en_slug_unique');
})->skip(fn (): bool => ! onDriver('pgsql'), 'postgres leg only');

it('hides the generated columns from SELECT * on mysql', function (): void {
    createTopics();
    SlugIndexes::ensure(SlugIndexSpec::localeMap('topics', 'slug', locales: ['en']));
    DB::table('topics')->insert(['slug' => json_encode(['en' => 'x'])]);

    expect(array_keys((array) DB::table('topics')->first()))->not->toContain('slug__en')
        ->and(Schema::hasColumn('topics', 'slug__en'))->toBeTrue();
})->skip(fn (): bool => ! onDriver('mysql', 'mariadb'), 'mysql leg only');

it('accepts Café next to cafe in a case-keeping locale map (binary collation)', function (): void {
    definitionFor(LocalizedPage::class, SlugDefinition::for('slug')->from('name')->lowercase(false)->unicode());

    $one = LocalizedPage::query()->create(['name' => ['en' => 'Café']]);
    $two = LocalizedPage::query()->create(['name' => ['en' => 'cafe']]);

    expect($one->slug)->toEqual(['en' => 'Café'])->and($two->slug)->toEqual(['en' => 'cafe']);
});

it('validates the supported locales a locale-map index defaults to before any DDL', function (): void {
    createTopics();
    config(['sluggable.locales.supported' => ['en', "sk')); drop table topics; --"]]);

    SlugIndexes::plan(SlugIndexSpec::localeMap('topics', 'slug'));
})->throws(InvalidLocaleException::class);
