<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tables the CMS keeps its content in.
 *
 * Every table is created only when it is missing. A migration that fails
 * half way - a database without DDL transactions leaves the tables it
 * already made behind - can then be run again and finish the job, instead
 * of stopping on the first table that already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createIfMissing('gadyacms_sites', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('key')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        /*
         * One row per editable page. The client-facing document keeps its
         * nested shape - the repository assembles it from these rows - but
         * the parts Filament needs to list, sort and filter on live in real
         * columns rather than inside the JSON.
         */
        $this->createIfMissing('gadyacms_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('gadyacms_sites')->cascadeOnDelete();
            $table->string('slug');
            $table->string('title');
            $table->string('type')->default('content');
            $table->string('status')->default('published');
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('draft')->nullable();
            $table->json('published')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'slug']);
            $table->index(['site_id', 'status']);
        });

        /*
         * Everything in the document that is not a page: the theme, the
         * navigation, the slug redirect table and the global contact
         * details. One row per top-level document key.
         */
        $this->createIfMissing('gadyacms_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('gadyacms_sites')->cascadeOnDelete();
            $table->string('key');
            $table->json('draft')->nullable();
            $table->json('published')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'key']);
        });

        $this->createIfMissing('gadyacms_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('gadyacms_sites')->cascadeOnDelete();
            $table->string('filename')->unique();
            $table->string('original_name');
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('thumbnail_path')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('alt_text')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_legacy')->default(false);
            $table->string('status')->default('ready');
            $table->timestamps();
        });

        $this->createIfMissing('gadyacms_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('gadyacms_sites')->cascadeOnDelete();
            $table->json('snapshot');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('label')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'published_at']);
        });

        $this->createIfMissing('gadyacms_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('gadyacms_sites')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event');
            $table->string('subject')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['site_id', 'event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gadyacms_audit_logs');
        Schema::dropIfExists('gadyacms_revisions');
        Schema::dropIfExists('gadyacms_media');
        Schema::dropIfExists('gadyacms_settings');
        Schema::dropIfExists('gadyacms_pages');
        Schema::dropIfExists('gadyacms_sites');
    }

    /**
     * Create a table only when it is missing, so a migration that failed
     * half way can be run again and finish the job.
     */
    private function createIfMissing(string $table, Closure $definition): void
    {
        if (! Schema::hasTable($table)) {
            Schema::create($table, $definition);
        }
    }
};
