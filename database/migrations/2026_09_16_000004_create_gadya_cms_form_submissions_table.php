<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createIfMissing('gadyacms_form_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('gadyacms_sites')->cascadeOnDelete();
            $table->string('form', 60);
            $table->json('data');
            $table->string('path')->nullable();
            $table->string('referrer_host')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('status', 20)->default('new');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at');
            $table->index(['site_id', 'form', 'created_at']);
            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gadyacms_form_submissions');
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
