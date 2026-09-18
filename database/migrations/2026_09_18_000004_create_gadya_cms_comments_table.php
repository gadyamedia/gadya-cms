<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * What readers say under an article. Nothing appears until someone
         * approves it, and no address is stored - only the same daily hash
         * the rest of the analytics uses, to spot a flood from one place.
         */
        Schema::create('gadyacms_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('gadyacms_sites')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('gadyacms_posts')->cascadeOnDelete();
            $table->string('author_name', 120);
            $table->string('author_email')->nullable();
            $table->text('body');
            $table->string('status', 20)->default('pending');
            $table->string('visitor_hash', 64)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'status', 'created_at']);
            $table->index(['post_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gadyacms_comments');
    }
};
