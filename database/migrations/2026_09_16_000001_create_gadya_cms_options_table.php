<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configuration that is not content: the AI provider and its key, who
     * gets the weekly digest, how forms are handled. It has no draft or
     * published copy and never appears in a revision, which is why it does
     * not live in the settings table.
     */
    public function up(): void
    {
        Schema::create('gadyacms_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('gadyacms_sites')->cascadeOnDelete();
            $table->string('key');
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gadyacms_options');
    }
};
