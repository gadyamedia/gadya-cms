<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createIfMissing('gadyacms_subscribers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('gadyacms_sites')->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('status', 20)->default('subscribed');
            $table->string('source')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'email']);
            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gadyacms_subscribers');
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
