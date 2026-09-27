<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Changes the client asked for in the Gadya Media portal, drafted here by
 * AI and waiting for a person to publish or discard. Kept so the portal
 * can be told which way it went, and so the same request delivered twice
 * is drafted once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gadyacms_change_requests')) {
            return;
        }

        Schema::create('gadyacms_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('gadyacms_sites')->cascadeOnDelete();
            $table->unsignedBigInteger('portal_id');
            $table->unsignedBigInteger('page_id')->nullable();
            $table->string('page_slug')->nullable();
            $table->string('page_title')->nullable();
            $table->text('instructions');
            $table->string('requested_by')->nullable();
            $table->string('status', 20)->default('drafted');
            $table->json('changes')->nullable();
            $table->text('preview_url')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'portal_id']);
            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gadyacms_change_requests');
    }
};
