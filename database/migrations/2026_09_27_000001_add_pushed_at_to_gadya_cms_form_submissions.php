<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an enquiry reached the Gadya Media portal. Empty until it has, so
 * a portal that was down or a queue that never ran is caught by the
 * five-minute sweep rather than the enquiry going unseen there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gadyacms_form_submissions', function (Blueprint $table): void {
            if (! Schema::hasColumn('gadyacms_form_submissions', 'pushed_at')) {
                $table->timestamp('pushed_at')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('gadyacms_form_submissions', function (Blueprint $table): void {
            if (Schema::hasColumn('gadyacms_form_submissions', 'pushed_at')) {
                $table->dropIndex(['pushed_at']);
                $table->dropColumn('pushed_at');
            }
        });
    }
};
