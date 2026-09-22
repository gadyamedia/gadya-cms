<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Support\BackupDrill;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Everyone says "backups included". This looks inside one, so there is
 * something to show a client besides a promise - and so we find out the
 * archive is empty on an ordinary Tuesday rather than on the worst day of
 * her year.
 */
class BackupDrillTest extends TestCase
{
    private function archive(string $sql, string $name = 'backup.zip'): void
    {
        Storage::fake('backups');
        config(['backup.backup.name' => 'Acme', 'backup.backup.destination.disks' => ['backups']]);

        $path = tempnam(sys_get_temp_dir(), 'drill-test-').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('db-dumps/mysql-forge.sql', $sql);
        $zip->addFromString('var/www/html/.env', 'APP_KEY=whatever');
        $zip->close();

        Storage::disk('backups')->put('Acme/'.$name, (string) file_get_contents($path));
        @unlink($path);
    }

    public function test_a_good_archive_passes_and_says_how_many_tables_it_holds(): void
    {
        $this->publishDocument();
        $this->archive(str_repeat("CREATE TABLE `pages` (id int, title varchar(255), body text);\n", 60));

        $result = app(BackupDrill::class)->run();

        $this->assertTrue($result['passed']);
        $this->assertSame(60, $result['tables']);
        $this->assertStringContainsString('could be restored', $result['says']);
        $this->assertTrue(app(BackupDrill::class)->last()['passed'], 'The result is kept, so the portal can show it.');
        $this->assertSame(60, app(BackupDrill::class)->last()['tables']);
    }

    public function test_an_archive_whose_dump_is_empty_fails_loudly(): void
    {
        $this->publishDocument();
        $this->archive('-- nothing here');

        $result = app(BackupDrill::class)->run();

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('running but saving nothing', $result['says']);
    }

    public function test_a_site_that_takes_no_backups_says_so_rather_than_passing(): void
    {
        $this->publishDocument();
        config(['backup.backup.destination.disks' => null]);

        $this->artisan('gadya-cms:backup-drill')->assertFailed();

        $this->assertStringContainsString('takes no backups', (string) app(BackupDrill::class)->last()['says']);
    }
}
