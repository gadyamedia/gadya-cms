<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Addresses on this site that do not work: ones a visitor asked
         * for and got nothing, and ones the site links to itself. Both are
         * the same problem to whoever has to fix them, so they live in one
         * list with a note of how each was found.
         */
        $this->createIfMissing('gadyacms_broken_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('gadyacms_sites')->cascadeOnDelete();
            $table->string('signature', 64);
            $table->string('url', 500);
            $table->string('kind', 10)->default('visited');
            $table->string('found_on', 500)->nullable();
            $table->string('referrer_host')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('hits')->default(1);
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'signature']);
            $table->index(['site_id', 'resolved_at', 'hits']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gadyacms_broken_links');
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
