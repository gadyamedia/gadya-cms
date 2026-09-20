<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Things happening on a date: an open day, a camp, a class. Their
         * own table rather than pages, because what matters about them is
         * when they are, and a list of them sorts itself.
         */
        $this->createIfMissing('gadyacms_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('gadyacms_sites')->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->string('summary', 500)->nullable();
            $table->longText('body')->nullable();
            $table->string('image')->nullable();
            $table->string('hero_alt')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->string('location')->nullable();
            $table->string('price', 80)->nullable();
            $table->string('booking_url', 500)->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamps();
            $table->unique(['site_id', 'slug']);
            $table->index(['site_id', 'status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gadyacms_events');
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
