<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Categories and tags. One table for both, told apart by `taxonomy`,
         * because they differ in how they are used rather than in what they
         * are: a category is the shelf an article sits on, a tag is a word
         * it shares with others.
         */
        $this->createIfMissing('gadyacms_terms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('gadyacms_sites')->cascadeOnDelete();
            $table->string('taxonomy', 20)->default('category');
            $table->string('name');
            $table->string('slug');
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['site_id', 'taxonomy', 'slug']);
        });

        $this->createIfMissing('gadyacms_post_term', function (Blueprint $table): void {
            $table->foreignId('post_id')->constrained('gadyacms_posts')->cascadeOnDelete();
            $table->foreignId('term_id')->constrained('gadyacms_terms')->cascadeOnDelete();
            $table->primary(['post_id', 'term_id']);
        });

        /*
         * A deleted page goes to the trash rather than out of existence, so
         * the wrong page deleted on a Friday can be put back on a Monday.
         */
        Schema::table('gadyacms_pages', function (Blueprint $table): void {
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gadyacms_pages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropSoftDeletes();
        });

        Schema::dropIfExists('gadyacms_post_term');
        Schema::dropIfExists('gadyacms_terms');
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
