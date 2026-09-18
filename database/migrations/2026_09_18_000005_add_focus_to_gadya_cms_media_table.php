<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gadyacms_media', function (Blueprint $table): void {
            $table->unsignedTinyInteger('focal_x')->default(50)->after('variants');
            $table->unsignedTinyInteger('focal_y')->default(50)->after('focal_x');
        });
    }

    public function down(): void
    {
        Schema::table('gadyacms_media', function (Blueprint $table): void {
            $table->dropColumn(['focal_x', 'focal_y']);
        });
    }
};
