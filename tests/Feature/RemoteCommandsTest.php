<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Portal\Commands\ComingSoonOff;
use Gadya\Cms\Portal\Commands\ComingSoonOn;
use Gadya\Cms\Portal\Commands\RunBackup;
use Gadya\Cms\Portal\RemoteCommands;
use Gadya\Cms\Support\Maintenance;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * What the portal may ask the site to do. gadya/connect checks the
 * signature and runs whichever tagged handler answers to the type; these
 * tests call the handlers the way it does.
 */
class RemoteCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
    }

    public function test_connect_finds_every_handler_by_its_tag_and_type(): void
    {
        $types = collect(app()->tagged(RemoteCommands::TAG))
            ->map(fn (object $handler): string => $handler->type())
            ->all();

        foreach (['coming_soon.on', 'coming_soon.off', 'backup.run'] as $type) {
            $this->assertContains($type, $types);
        }

        foreach (app()->tagged(RemoteCommands::TAG) as $handler) {
            $this->assertTrue(method_exists($handler, 'handle'), $handler::class.' must have handle(array $payload).');
        }
    }

    public function test_the_portal_can_close_the_site_and_open_it_again(): void
    {
        $maintenance = app(Maintenance::class);

        $result = app(ComingSoonOn::class)->handle(['heading' => 'Back on Monday', 'until' => now()->addDays(2)->toIso8601String()]);

        $this->assertTrue($maintenance->isOn());
        $this->assertSame('Back on Monday', $maintenance->heading());
        $this->assertTrue($result['result']['enabled']);
        $this->assertNotNull($result['result']['until']);
        $this->assertStringContainsString('Back on Monday', $result['output']);
        $this->assertDatabaseHas('gadyacms_audit_logs', ['event' => 'site.maintenance_on']);

        $result = app(ComingSoonOff::class)->handle([]);

        $this->assertFalse(app(Maintenance::class)->isOn());
        $this->assertFalse($result['result']['enabled']);
        $this->assertStringContainsString('open to everyone', $result['output']);
    }

    public function test_the_notice_stays_as_the_client_wrote_it_when_the_portal_says_nothing(): void
    {
        app(Maintenance::class)->save(['heading' => 'Painting the walls']);

        app(ComingSoonOn::class)->handle([]);

        $this->assertSame('Painting the walls', app(Maintenance::class)->heading());
    }

    public function test_a_backup_runs_the_sites_own_backup_command(): void
    {
        config(['backup.backup.destination.disks' => ['local'], 'backup.backup.name' => 'site']);

        $options = [];
        Artisan::command('backup:run {--only-db}', function () use (&$options): int {
            $options = ['only_db' => $this->option('only-db')];
            $this->line('Backup completed!');

            return 0;
        });

        $result = app(RunBackup::class)->handle(['only_db' => true]);

        $this->assertTrue($options['only_db']);
        $this->assertStringContainsString('Backup completed!', $result['output']);
        $this->assertTrue($result['result']['configured']);
    }

    public function test_a_backup_that_fails_fails_the_command_with_the_reason(): void
    {
        config(['backup.backup.destination.disks' => ['local']]);

        Artisan::command('backup:run {--only-db}', function (): int {
            $this->line('Disk full');

            return 1;
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Disk full');

        app(RunBackup::class)->handle([]);
    }

    public function test_a_site_without_backups_says_so_rather_than_pretending(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('takes no backups');

        app(RunBackup::class)->handle([]);
    }
}
