<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Gadya CMS put right on the client's behalf, so she can read it,
 * see what the words were before, and change her mind.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gadyacms_fixes')) {
            return;
        }

        Schema::create('gadyacms_fixes', function (Blueprint $table): void {
            $table->id();
            $table->string('audit');
            $table->string('subject');
            $table->text('before')->nullable();
            $table->text('after')->nullable();
            $table->string('written_by')->default('gadya');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gadyacms_fixes');
    }
};
