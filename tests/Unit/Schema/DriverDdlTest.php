<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugIndexSpec;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\Schema\Drivers\MysqlSlugIndexes;
use RoundlyConsulting\Sluggable\Schema\Drivers\PostgresSlugIndexes;
use RoundlyConsulting\Sluggable\Schema\SlugIndexes;
use RoundlyConsulting\Sluggable\Tests\Fixtures\LocalizedPage;

/*
 * DDL snapshots from the grammar alone — no engine needed (the real-engine legs prove the
 * DDL actually applies and enforces). Connections are built lazily and never opened here.
 */

beforeEach(function (): void {
    config([
        'database.connections.ddl_pgsql' => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '1', 'database' => 'x', 'username' => 'x', 'password' => '', 'prefix' => ''],
        'database.connections.ddl_mysql' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '1', 'database' => 'x', 'username' => 'x', 'password' => '', 'prefix' => ''],
    ]);
});

it('plans postgres string indexes, partial when trashed rows are exempt', function (): void {
    $connection = DB::connection('ddl_pgsql');

    [$include] = (new PostgresSlugIndexes)->plan(SlugIndexSpec::string('articles', 'slug', scope: ['tenant_id']), $connection, []);
    [$exclude] = (new PostgresSlugIndexes)->plan(SlugIndexSpec::string('articles', 'slug', includeTrashed: false), $connection, []);

    expect($include->statements)->toBe(['create unique index if not exists "articles_slug_slug_unique" on "articles" ("tenant_id", "slug")'])
        ->and($exclude->statements)->toBe(['create unique index if not exists "articles_slug_slug_unique" on "articles" ("slug") where "deleted_at" is null']);
});

it('plans postgres per-locale expression indexes', function (): void {
    $planned = (new PostgresSlugIndexes)->plan(SlugIndexSpec::localeMap('topics', 'slug', scope: ['shop_id']), DB::connection('ddl_pgsql'), ['en', 'pt-BR']);

    expect($planned[0]->statements)->toBe(['create unique index if not exists "topics_slug_en_slug_unique" on "topics" ("shop_id", ("slug"->>\'en\'))'])
        ->and($planned[1]->name)->toBe('topics_slug_pt_br_slug_unique')
        ->and($planned[1]->statements[0])->toContain('("slug"->>\'pt-BR\')');
});

it('plans a plain mysql unique index for include-trashed string slugs', function (): void {
    [$index] = (new MysqlSlugIndexes)->plan(SlugIndexSpec::string('articles', 'slug', scope: ['tenant_id']), DB::connection('ddl_mysql'), []);

    expect($index->ddl())->toBe(['alter table `articles` add unique index `articles_slug_slug_unique` (`tenant_id`, `slug`)'])
        ->and($index->generatedColumn)->toBeNull();
});

/** The MySQL locale-map planner only needs PDO::quote(); a SQLite PDO stands in, so no server is opened. */
function mysqlLocaleColumnDdl(SlugIndexSpec $spec): string
{
    $connection = DB::connection('ddl_mysql');
    $connection->setPdo(new PDO('sqlite::memory:'));

    return (string) (new MysqlSlugIndexes)->plan($spec, $connection, ['en'])[0]->generatedColumnStatement;
}

it('sizes the mysql locale column to the definition maxLength, never below 255', function (?int $maxLength, string $type): void {
    $definition = SlugDefinition::for('slug')->from('name')->routeKey();
    definitionFor(LocalizedPage::class, $maxLength === null ? $definition : $definition->maxLength($maxLength));

    [$spec] = SlugIndexes::specsFor(LocalizedPage::class);

    expect(mysqlLocaleColumnDdl($spec))
        ->toStartWith('alter table `localized_pages` add column `slug__en` '.$type.' collate utf8mb4_bin as ');
})->with([
    'default' => [null, 'varchar(255)'],
    'shorter' => [100, 'varchar(255)'],
    'longer' => [500, 'varchar(500)'],
    'index limit' => [768, 'varchar(768)'],
]);

it('refuses a mysql locale column longer than a utf8mb4 unique index can hold', function (): void {
    definitionFor(LocalizedPage::class, SlugDefinition::for('slug')->from('name')->maxLength(769));

    [$spec] = SlugIndexes::specsFor(LocalizedPage::class);

    mysqlLocaleColumnDdl($spec);
})->throws(InvalidSlugDefinitionException::class, 'MySQL can index a locale-map slug of at most 768 characters');

it('takes maxLength on a hand-written locale-map spec and rejects one outside 1-2048', function (): void {
    expect(mysqlLocaleColumnDdl(SlugIndexSpec::localeMap('topics', maxLength: 600)))->toContain('`slug__en` varchar(600) collate')
        ->and(SlugIndexSpec::localeMap('topics')->maxLength)->toBeNull()
        ->and(fn () => SlugIndexSpec::localeMap('topics', maxLength: 0))->toThrow(InvalidSlugDefinitionException::class, 'use 1-2048 characters')
        ->and(fn () => new SlugIndexSpec('topics', 'slug', maxLength: 2049))->toThrow(InvalidSlugDefinitionException::class, 'use 1-2048 characters');
});
