<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A divider, a texture, a flourish: pictures that say nothing, whose
 * right description is none at all. Marking them decorative is what lets
 * "every photo needs a description" be a rule without exceptions nobody
 * can tell apart from the photos somebody forgot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gadyacms_media', function (Blueprint $table): void {
            if (! Schema::hasColumn('gadyacms_media', 'decorative')) {
                $table->boolean('decorative')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('gadyacms_media', function (Blueprint $table): void {
            if (Schema::hasColumn('gadyacms_media', 'decorative')) {
                $table->dropColumn('decorative');
            }
        });
    }
};
