<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A restaurant's menus: breakfast, lunch, drinks. Their own tables
         * rather than the site document, because a sold-out bagel has to
         * come off the site the moment the counter says so - not at the
         * next Publish - and because a till or an ordering service will one
         * day want to know which item is which. Every row carries a `key`
         * that survives renames for exactly that, and prices are whole
         * cents so nothing is ever rounded twice.
         */
        $this->createIfMissing('gadyacms_menus', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('gadyacms_sites')->cascadeOnDelete();
            $table->string('key', 26);
            $table->string('name');
            $table->string('slug');
            $table->string('description', 1000)->nullable();
            $table->string('availability')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['site_id', 'slug']);
            $table->unique(['site_id', 'key']);
        });

        $this->createIfMissing('gadyacms_menu_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_id')->constrained('gadyacms_menus')->cascadeOnDelete();
            $table->string('key', 26)->unique();
            $table->string('name');
            $table->string('description', 1000)->nullable();
            $table->string('availability')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['menu_id', 'sort_order']);
        });

        $this->createIfMissing('gadyacms_menu_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_section_id')->constrained('gadyacms_menu_sections')->cascadeOnDelete();
            $table->string('key', 26)->unique();
            $table->string('name');
            $table->string('description', 1000)->nullable();
            $table->unsignedInteger('price_cents')->nullable();
            $table->json('variants')->nullable();
            $table->json('dietary')->nullable();
            $table->string('image')->nullable();
            $table->string('image_alt')->nullable();
            $table->string('availability')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_sold_out')->default(false);
            $table->boolean('is_visible')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['menu_section_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gadyacms_menu_items');
        Schema::dropIfExists('gadyacms_menu_sections');
        Schema::dropIfExists('gadyacms_menus');
    }

    /**
     * Create a table only when it is missing, so a migration that failed
     * half way can be run again and finish the job.
     */
    private function createIfMissing(string $table, Closure $definition): void
    {
        if (! Schema::hasTable($table)) {
            Schema::create($table, $definition);
        }
    }
};
