<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gadyacms_media', function (Blueprint $table): void {
            $table->string('folder', 80)->nullable()->index()->after('alt_text');
            $table->json('tags')->nullable()->after('folder');
        });
    }

    public function down(): void
    {
        Schema::table('gadyacms_media', function (Blueprint $table): void {
            $table->dropColumn(['folder', 'tags']);
        });
    }
};
