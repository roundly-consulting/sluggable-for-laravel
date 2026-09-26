<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugIndexSpec;
use RoundlyConsulting\Sluggable\Schema\SlugIndexes;

/**
 * Host-owned fixture tables (never published). Unique indexes are real, so the collision-retry
 * path runs against actual constraints on every engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->slug()->nullable();
            $table->string('title')->nullable();
            $table->string('code')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->boolean('published')->default(false);
            $table->string('email')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();
            $table->uniqueSlug('slug');
        });

        Schema::create('plain_things', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->slug()->nullable();
            $table->timestamps();
            $table->uniqueSlug('slug');
        });

        Schema::create('scoped_items', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->slug()->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('brands', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('multi_slug_things', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('name')->nullable();
            $table->string('sku')->nullable();
            $table->slug()->nullable();
            $table->slug('handle')->nullable();
            $table->slug('code')->nullable();
            $table->timestamps();
        });

        Schema::create('localized_pages', function (Blueprint $table): void {
            $table->id();
            $table->jsonb('name')->nullable();
            $table->localizedSlug('slug')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->boolean('published')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('contract_pages', function (Blueprint $table): void {
            $table->id();
            $table->jsonb('name')->nullable();
            $table->localizedSlug('slug')->nullable();
            $table->timestamps();
        });

        Schema::create('uuid_things', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->slug()->nullable();
            $table->timestamps();
        });

        Schema::create('clinics', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->slug()->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('clinic_products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('clinic_id');
            $table->string('name');
            $table->slug()->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('clinic_categories', function (Blueprint $table): void {
            $table->id();
            $table->jsonb('name')->nullable();
            $table->localizedSlug('slug')->nullable();
            $table->timestamps();
        });

        Schema::create('clinic_clinic_category', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('clinic_id');
            $table->unsignedBigInteger('clinic_category_id');
        });

        SlugIndexes::ensure(SlugIndexSpec::string('scoped_items', 'slug', scope: ['tenant_id']));
        SlugIndexes::ensure(SlugIndexSpec::string('clinic_products', 'slug', scope: ['clinic_id']));
        SlugIndexes::ensure(SlugIndexSpec::localeMap('localized_pages', 'slug', locales: ['en', 'sk', 'de']));
        SlugIndexes::ensure(SlugIndexSpec::localeMap('contract_pages', 'slug', locales: ['en', 'sk', 'de']));
        SlugIndexes::ensure(SlugIndexSpec::string('uuid_things', 'slug'));
    }
};
