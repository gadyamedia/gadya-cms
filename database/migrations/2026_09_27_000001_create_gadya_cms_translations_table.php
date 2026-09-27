<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The words of the site in its other languages. A table of its own rather
 * than new columns on the pages and settings: nothing the site already
 * stores changes shape, so an existing site migrates without touching a
 * row, and a site with one language never reads this table at all.
 *
 * One row per language per piece of content - a page, a settings key, an
 * article - holding only the translated words, laid over the default
 * language when the page is drawn.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gadyacms_translations')) {
            return;
        }

        Schema::create('gadyacms_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('gadyacms_sites')->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('key');
            $table->json('draft')->nullable();
            $table->json('published')->nullable();
            $table->string('source', 20)->default('manual');
            $table->boolean('needs_review')->default(false);
            $table->string('source_hash', 64)->nullable();
            $table->timestamp('translated_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['site_id', 'locale', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gadyacms_translations');
    }
};
