<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Support\InstallAudit;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;

class InstallAuditTest extends TestCase
{
    public function test_config_keys_a_release_added_are_reported_but_site_owned_maps_are_not(): void
    {
        $config = config('gadya-cms');
        unset($config['seo']['content_signals'], $config['events']);
        $config['users']['roles'] = ['boss' => 'Boss'];
        config(['gadya-cms' => $config]);

        $missing = app(InstallAudit::class)->missingConfigKeys();

        $this->assertContains('seo.content_signals', $missing);
        $this->assertContains('events', $missing);
        $this->assertNotContains('users.roles.admin', $missing, 'Role names are the site\'s own, not missing keys.');
    }

    public function test_it_finds_unscheduled_jobs_and_features_switched_off(): void
    {
        app(Schedule::class)->command('gadya-cms:publish-due')->everyFiveMinutes();
        config(['gadya-cms.events.routes' => false]);

        $checks = collect(app(InstallAudit::class)->checks())->keyBy('label');

        $this->assertSame(InstallAudit::OK, $checks['gadya-cms:publish-due is scheduled']['status']);
        $this->assertSame(InstallAudit::TODO, $checks['gadya-cms:prune-trash is scheduled']['status']);
        $this->assertStringContainsString("Schedule::command('gadya-cms:prune-trash')->daily();", $checks['gadya-cms:prune-trash is scheduled']['fix']);
        $this->assertSame(InstallAudit::TODO, $checks["What's on pages and calendar files (events.routes)"]['status']);
        $this->assertSame(InstallAudit::OK, $checks['Plugin ->blog() is on']['status']);
        $this->assertSame(InstallAudit::OK, $checks['Every package migration has run']['status']);
    }

    public function test_comments_are_offered_not_demanded(): void
    {
        $check = collect(app(InstallAudit::class)->checks())->firstWhere('label', 'Comments on articles (blog.comments.enabled)');

        $this->assertSame(InstallAudit::OPTIONAL, $check['status']);
    }

    public function test_the_command_reports_as_json_and_fails_while_anything_is_left_to_do(): void
    {
        $this->artisan('gadya-cms:audit', ['--json' => true])
            ->expectsOutputToContain('"todo":')
            ->assertFailed();
    }

    public function test_the_upgrade_skill_names_only_docs_and_jobs_that_exist(): void
    {
        $skill = (string) file_get_contents(__DIR__.'/../../resources/boost/skills/gadya-cms-upgrade/SKILL.md');

        preg_match_all('/`([a-z-]+\.md)`/', $skill, $docs);

        $this->assertNotEmpty($docs[1]);

        foreach (array_unique($docs[1]) as $doc) {
            $this->assertFileExists(__DIR__.'/../../docs/'.$doc, "The upgrade skill points at {$doc}, which does not exist.");
        }

        $this->assertStringContainsString('gadya-cms:audit', $skill);
    }
}
