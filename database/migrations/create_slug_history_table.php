<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\Sluggable\Support\SluggableConfig;

return new class extends Migration
{
    public function up(): void
    {
        // The same resolution as the history model: blank means the default, and the name must be
        // an identifier.
        $name = SluggableConfig::historyTable();

        // The morph id must match the primary key type of the SLUGGED models.
        $keyType = KeyType::fromConfig('sluggable.key_type');

        Schema::create($name, function (Blueprint $table) use ($keyType): void {
            $table->id();
            $table->morphKey('sluggable', $keyType);
            $table->string('column', 64);
            // '' for string slugs: a NULL would make every row distinct to the unique index.
            // 35 chars = the RFC 5646 minimum supported tag length (and sluggable's locale cap).
            $table->string('locale', 35)->default('');
            $table->string('slug', 255);
            $table->auditable();

            $table->index(['sluggable_type', 'column', 'locale', 'slug'], 'slug_history_lookup_index');
            $table->unique(['sluggable_type', 'sluggable_id', 'column', 'locale', 'slug'], 'slug_history_entry_unique');
        });
    }
};
