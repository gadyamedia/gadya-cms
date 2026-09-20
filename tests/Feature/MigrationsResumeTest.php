<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A database without DDL transactions - MySQL - keeps the tables a failed
 * migration already made, and does not record the migration as run. The
 * deploy that follows runs it again, and it has to finish the job rather
 * than stop on the first table that is already there.
 */
class MigrationsResumeTest extends TestCase
{
    public function test_it_finishes_the_job_after_a_migration_failed_half_way(): void
    {
        /* As a half-finished run leaves it: the early tables, not the later ones. */
        Schema::dropIfExists('gadyacms_pages');
        DB::table('gadyacms_sites')->insert(['name' => 'Already here', 'key' => 'default', 'is_active' => true]);

        $this->migration()->up();

        $this->assertTrue(Schema::hasTable('gadyacms_pages'), 'The missing table is made on the second run.');
        $this->assertSame('Already here', DB::table('gadyacms_sites')->value('name'), 'The table that survived is left as it was.');
    }

    public function test_running_it_over_a_finished_database_changes_nothing(): void
    {
        DB::table('gadyacms_sites')->insert(['name' => 'Already here', 'key' => 'default', 'is_active' => true]);

        $this->migration()->up();

        $this->assertSame('Already here', DB::table('gadyacms_sites')->value('name'));
    }

    private function migration(): object
    {
        return require __DIR__.'/../../database/migrations/2026_09_14_000001_create_gadya_cms_tables.php';
    }
}
