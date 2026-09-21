<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Lighthouse found wrong, beside how it scored: each failing audit
 * and the elements it failed on, so the panel can say which photo has no
 * description rather than only that some do not.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('gadyacms_page_scores', 'failures')) {
            return;
        }

        Schema::table('gadyacms_page_scores', function (Blueprint $table): void {
            $table->json('failures')->nullable()->after('opportunities');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('gadyacms_page_scores', 'failures')) {
            return;
        }

        Schema::table('gadyacms_page_scores', function (Blueprint $table): void {
            $table->dropColumn('failures');
        });
    }
};
