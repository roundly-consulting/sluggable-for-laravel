<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugIndexSpec;
use RoundlyConsulting\Sluggable\Schema\Drivers\MysqlSlugIndexes;
use RoundlyConsulting\Sluggable\Schema\Drivers\PostgresSlugIndexes;

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
