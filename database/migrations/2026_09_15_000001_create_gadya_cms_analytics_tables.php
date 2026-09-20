<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A page view holds no IP address and no cookie: the visitor is a
         * daily-rotating HMAC, which is enough to count people once a day
         * and useless for following anyone across days.
         */
        $this->createIfMissing('gadyacms_page_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('gadyacms_sites')->nullOnDelete();
            $table->string('path');
            $table->string('route_name')->nullable();
            $table->string('visitor_hash', 64)->index();
            $table->string('referrer_host')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('device_category', 20)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('region')->nullable();
            $table->string('city')->nullable();
            $table->timestamp('viewed_at')->index();

            $table->index(['site_id', 'viewed_at']);
            $table->index(['path', 'viewed_at']);
        });

        $this->createIfMissing('gadyacms_analytics_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('gadyacms_sites')->nullOnDelete();
            $table->string('name')->index();
            $table->string('path');
            $table->string('visitor_hash', 64);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gadyacms_analytics_events');
        Schema::dropIfExists('gadyacms_page_views');
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
