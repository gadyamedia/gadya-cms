<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A site may decide who is an administrator for itself - from an
 * `is_admin` flag, or from another package's roles - and expose `role` as
 * an accessor rather than a column. Nothing here may fail with a database
 * error because of that.
 */
class SiteWithoutARoleColumnTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }

    public function test_installing_leaves_the_first_account_to_the_site(): void
    {
        $this->artisan('gadya-cms:install', ['--no-interaction' => true])
            ->expectsOutputToContain('decides roles for itself')
            ->assertSuccessful();
    }

    public function test_making_an_editor_says_the_role_is_the_site_to_give(): void
    {
        $this->artisan('gadya-cms:editor', [
            '--name' => 'Erin Editor',
            '--email' => 'erin@example.com',
            '--password' => 'a-long-enough-password',
        ])
            ->expectsOutputToContain('decides roles for itself')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'erin@example.com']);
    }
}
