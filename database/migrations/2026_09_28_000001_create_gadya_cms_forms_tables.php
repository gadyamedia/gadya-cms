<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forms the client builds in the panel, alongside the ones a developer
 * configures. Tables of their own, so nothing the site already stores
 * changes shape: the enquiries inbox only gains a few nullable columns,
 * and a site that never builds a form never reads the rest.
 *
 * - `gadyacms_forms` - one row per form: its fields and steps, the words
 *   it says, and where each enquiry goes.
 * - `gadyacms_form_versions` - every saved shape of a form, so an enquiry
 *   from last spring still reads against the questions it was asked.
 * - `gadyacms_form_events` - views, starts, steps and completions, for
 *   the form's own figures.
 * - `gadyacms_form_drafts` - a half-filled form someone asked to finish
 *   later, behind a token only their email holds.
 * - `gadyacms_form_webhook_deliveries` - every attempt to hand an enquiry
 *   to Zapier, Make or anything else, and what it answered.
 *
 * Each table is guarded, so a run that failed half-way on a database
 * without DDL transactions finishes the job on the next deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gadyacms_forms')) {
            Schema::create('gadyacms_forms', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->constrained('gadyacms_sites')->cascadeOnDelete();
                $table->string('slug', 80);
                $table->string('title', 160);
                $table->text('description')->nullable();
                $table->string('status', 20)->default('draft');
                $table->json('fields')->nullable();
                $table->json('messages')->nullable();
                $table->json('settings')->nullable();
                $table->unsignedInteger('version')->default(1);
                $table->string('template', 60)->nullable();
                $table->timestamp('published_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['site_id', 'slug']);
            });
        }

        if (! Schema::hasTable('gadyacms_form_versions')) {
            Schema::create('gadyacms_form_versions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('form_id')->constrained('gadyacms_forms')->cascadeOnDelete();
                $table->unsignedInteger('version');
                $table->json('fields')->nullable();
                $table->json('messages')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->unique(['form_id', 'version']);
            });
        }

        if (! Schema::hasTable('gadyacms_form_events')) {
            Schema::create('gadyacms_form_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->constrained('gadyacms_sites')->cascadeOnDelete();
                $table->foreignId('form_id')->constrained('gadyacms_forms')->cascadeOnDelete();
                $table->string('name', 20);
                $table->unsignedSmallInteger('step')->nullable();
                $table->string('visitor_hash', 64)->nullable();
                $table->string('path')->nullable();
                $table->string('referrer_host')->nullable();
                $table->unsignedInteger('duration_seconds')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['form_id', 'name', 'created_at']);
            });
        }

        if (! Schema::hasTable('gadyacms_form_drafts')) {
            Schema::create('gadyacms_form_drafts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('form_id')->constrained('gadyacms_forms')->cascadeOnDelete();
                $table->string('token_hash', 64)->unique();
                $table->string('email');
                $table->json('data')->nullable();
                $table->unsignedSmallInteger('step')->default(0);
                $table->string('path')->nullable();
                $table->timestamp('expires_at');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('gadyacms_form_webhook_deliveries')) {
            Schema::create('gadyacms_form_webhook_deliveries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('form_id')->constrained('gadyacms_forms')->cascadeOnDelete();
                $table->unsignedBigInteger('submission_id')->nullable()->index();
                $table->string('url', 500);
                $table->string('status', 20)->default('pending');
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->unsignedSmallInteger('response_status')->nullable();
                $table->text('response_body')->nullable();
                $table->string('error', 500)->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('gadyacms_form_submissions', function (Blueprint $table): void {
            if (! Schema::hasColumn('gadyacms_form_submissions', 'form_id')) {
                $table->unsignedBigInteger('form_id')->nullable()->index();
            }

            if (! Schema::hasColumn('gadyacms_form_submissions', 'form_version')) {
                $table->unsignedInteger('form_version')->nullable();
            }

            if (! Schema::hasColumn('gadyacms_form_submissions', 'files')) {
                $table->json('files')->nullable();
            }

            if (! Schema::hasColumn('gadyacms_form_submissions', 'meta')) {
                $table->json('meta')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('gadyacms_form_submissions', function (Blueprint $table): void {
            foreach (['form_id', 'form_version', 'files', 'meta'] as $column) {
                if (Schema::hasColumn('gadyacms_form_submissions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('gadyacms_form_webhook_deliveries');
        Schema::dropIfExists('gadyacms_form_drafts');
        Schema::dropIfExists('gadyacms_form_events');
        Schema::dropIfExists('gadyacms_form_versions');
        Schema::dropIfExists('gadyacms_forms');
    }
};
