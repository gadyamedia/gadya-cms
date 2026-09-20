<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * What Google Search Console says about the site, fetched on a
         * schedule and kept here so the dashboard never waits on Google.
         */
        $this->createIfMissing('gadyacms_search_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('gadyacms_sites')->cascadeOnDelete();
            $table->string('kind', 10);
            $table->string('key', 500);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 6, 4)->default(0);
            $table->decimal('position', 6, 2)->default(0);
            $table->date('period_start');
            $table->date('period_end');
            $table->timestamp('fetched_at');
            $table->index(['site_id', 'kind', 'period_end']);
        });

        /*
         * Lighthouse scores from the PageSpeed Insights API, one row per
         * check, so a page's history can be read back.
         */
        $this->createIfMissing('gadyacms_page_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('gadyacms_sites')->cascadeOnDelete();
            $table->string('path');
            $table->string('strategy', 10)->default('mobile');
            $table->unsignedTinyInteger('performance')->nullable();
            $table->unsignedTinyInteger('accessibility')->nullable();
            $table->unsignedTinyInteger('best_practices')->nullable();
            $table->unsignedTinyInteger('seo')->nullable();
            $table->unsignedInteger('lcp_ms')->nullable();
            $table->decimal('cls', 6, 3)->nullable();
            $table->json('opportunities')->nullable();
            $table->timestamp('checked_at');
            $table->index(['site_id', 'path', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gadyacms_page_scores');
        Schema::dropIfExists('gadyacms_search_snapshots');
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
