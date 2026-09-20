<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Articles are ordinary rows rather than part of the site document:
         * a blog grows without bound, is listed and paged, and is written
         * by one person at a time - none of which the draft/publish
         * document model is shaped for.
         */
        $this->createIfMissing('gadyacms_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('gadyacms_sites')->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->text('excerpt')->nullable();
            $table->longText('content')->nullable();
            $table->string('image')->nullable();
            $table->string('hero_alt')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->json('faq')->nullable();
            $table->string('reading_time', 20)->nullable();
            $table->string('target_keyword')->nullable();
            $table->string('target_location')->nullable();
            $table->string('search_intent', 20)->nullable();
            $table->string('source', 20)->default('manual');
            $table->json('ai_meta')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['site_id', 'slug']);
            $table->index(['site_id', 'status', 'published_at']);
        });

        $this->createIfMissing('gadyacms_article_generations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('gadyacms_sites')->cascadeOnDelete();
            $table->string('status', 20)->default('queued');
            $table->string('stage')->nullable();
            $table->string('topic', 500);
            $table->string('target_keyword')->nullable();
            $table->string('target_location')->nullable();
            $table->string('link_slug')->nullable();
            $table->string('search_intent', 20)->default('informational');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('failure_reason', 1000)->nullable();
            $table->foreignId('post_id')->nullable()->constrained('gadyacms_posts')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gadyacms_article_generations');
        Schema::dropIfExists('gadyacms_posts');
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
