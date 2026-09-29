<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('topic')->nullable();
            $table->text('message')->nullable();
            $table->string('status')->default('open');
            $table->string('source')->nullable();
            $table->string('landing_page', 500)->nullable();
            $table->string('brand')->nullable();
            $table->string('locale', 10)->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->string('privacy_version')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
