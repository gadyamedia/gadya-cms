<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a visitor agreed to when she asked to be rung back: the exact
 * words beside the box she ticked, when, and from where. A phone call
 * without that record is one nobody can prove was wanted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gadyacms_form_submissions', function (Blueprint $table): void {
            if (! Schema::hasColumn('gadyacms_form_submissions', 'consent')) {
                $table->json('consent')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('gadyacms_form_submissions', function (Blueprint $table): void {
            if (Schema::hasColumn('gadyacms_form_submissions', 'consent')) {
                $table->dropColumn('consent');
            }
        });
    }
};
