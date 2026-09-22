<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An enquiry is not finished when it is opened. It is finished when
 * someone has answered it - and sometimes not even then, if the answer
 * was "call me in March". Notes, a follow-up date and the day it was
 * answered turn the inbox into the small CRM a small business needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gadyacms_form_submissions', function (Blueprint $table): void {
            if (! Schema::hasColumn('gadyacms_form_submissions', 'notes')) {
                $table->text('notes')->nullable();
            }

            if (! Schema::hasColumn('gadyacms_form_submissions', 'follow_up_at')) {
                $table->timestamp('follow_up_at')->nullable()->index();
            }

            if (! Schema::hasColumn('gadyacms_form_submissions', 'answered_at')) {
                $table->timestamp('answered_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('gadyacms_form_submissions', function (Blueprint $table): void {
            foreach (['notes', 'follow_up_at', 'answered_at'] as $column) {
                if (Schema::hasColumn('gadyacms_form_submissions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
