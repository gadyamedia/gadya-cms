<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Mail\PortalMail;
use Gadya\Cms\Portal\Reviews;
use Gadya\Cms\Redirects\RedirectMap;
use Gadya\Cms\Tests\TestCase;
use Gadya\Cms\Upgrade\Steps\CheckUpdateWorkflow;
use Gadya\Cms\Upgrade\Steps\ClearCmsCaches;
use Gadya\Cms\Upgrade\Steps\CreateNotificationsTable;
use Gadya\Cms\Upgrade\Steps\LinkPublicStorage;
use Gadya\Cms\Upgrade\UpgradeSteps;
use Gadya\Cms\Upgrade\WorkflowTemplate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The steps gadya/connect's `gadya:upgrade` runs for this package. They
 * are plain tagged classes, tested here on their own; the last test runs
 * them through connect's runner when the installed connect has one.
 */
class UpgradeStepsTest extends TestCase
{
    private string $publicPath;

    protected function setUp(): void
    {
        parent::setUp();

        /* A public folder of its own, where the photo library's link is still to be made. */
        $this->publicPath = sys_get_temp_dir().'/gadya-cms-public-'.uniqid();
        File::ensureDirectoryExists($this->publicPath);
        config(['filesystems.links' => [$this->publicPath.'/storage' => storage_path('app/public')]]);
    }

    protected function tearDown(): void
    {
        foreach (File::glob(database_path('migrations/*_create_notifications_table.php')) as $migration) {
            File::delete($migration);
        }

        File::delete([database_path('migrations/2020_01_01_000000_bell.php'), database_path('schema/sqlite-schema.sql'), base_path(WorkflowTemplate::PATH)]);
        @unlink($this->publicPath.'/storage');
        File::deleteDirectory($this->publicPath);

        parent::tearDown();
    }

    public function test_every_step_is_tagged_for_connect_with_the_methods_it_calls(): void
    {
        $steps = iterator_to_array(app()->tagged(UpgradeSteps::TAG), false);

        $this->assertSame(UpgradeSteps::STEPS, array_map(fn (object $step): string => $step::class, $steps));

        foreach ($steps as $step) {
            foreach (['key', 'description', 'phase', 'shouldRun', 'run'] as $method) {
                $this->assertTrue(method_exists($step, $method), $step::class." has {$method}().");
            }

            $this->assertContains($step->phase(), [UpgradeSteps::CODE, UpgradeSteps::SERVER]);
            $this->assertStringStartsWith('cms.', $step->key());
        }

        $keys = array_map(fn (object $step): string => $step->key(), $steps);
        $this->assertSame(array_unique($keys), $keys);
    }

    public function test_the_notifications_migration_is_added_once(): void
    {
        $step = app(CreateNotificationsTable::class);

        $this->assertSame('code', $step->phase());
        $this->assertTrue($step->shouldRun(), 'The panel runs Gadya CMS and there is no migration yet.');

        $step->run();

        $this->assertCount(1, File::glob(database_path('migrations/*_create_notifications_table.php')));
        $this->assertFalse($step->shouldRun(), 'Done: it has nothing more to do.');
    }

    public function test_a_notifications_table_the_app_already_creates_is_left_alone(): void
    {
        File::ensureDirectoryExists(database_path('migrations'));
        File::put(database_path('migrations/2020_01_01_000000_bell.php'), "<?php\nSchema::create('notifications', function (Blueprint \$table) {});\n");

        $this->assertFalse(app(CreateNotificationsTable::class)->shouldRun());

        File::delete(database_path('migrations/2020_01_01_000000_bell.php'));
        File::ensureDirectoryExists(database_path('schema'));
        File::put(database_path('schema/sqlite-schema.sql'), "CREATE TABLE IF NOT EXISTS \"notifications\" (\"id\" varchar not null);\n");

        $this->assertFalse(app(CreateNotificationsTable::class)->shouldRun(), 'A squashed schema that creates it counts.');
    }

    public function test_an_old_update_workflow_is_reported_but_never_written(): void
    {
        $step = app(CheckUpdateWorkflow::class);

        $this->assertSame(4, WorkflowTemplate::shipped());
        $this->assertTrue($step->shouldRun());
        $this->assertStringContainsString('There is no .github/workflows/gadya-update.yml', $step->run());
        $this->assertFileDoesNotExist(base_path(WorkflowTemplate::PATH));

        File::ensureDirectoryExists(dirname(base_path(WorkflowTemplate::PATH)));
        File::put(base_path(WorkflowTemplate::PATH), "# Updates the Gadya packages and puts the result in git.\nname: Gadya update\n");

        $this->assertSame(1, WorkflowTemplate::installed(), 'The first template carried no number.');
        $this->assertTrue($step->shouldRun());
        $this->assertStringContainsString('is template 1; gadya/cms ships template '.WorkflowTemplate::shipped(), $step->run());
        $this->assertStringStartsWith('# Updates', File::get(base_path(WorkflowTemplate::PATH)), 'GitHub does not let a workflow write workflows.');

        File::copy(WorkflowTemplate::templatePath(), base_path(WorkflowTemplate::PATH));

        $this->assertFalse($step->shouldRun());
    }

    public function test_the_photo_library_is_linked_where_the_link_is_missing(): void
    {
        $step = app(LinkPublicStorage::class);

        $this->assertSame('server', $step->phase());
        $this->assertTrue($step->shouldRun());

        $step->run();

        $this->assertTrue(is_link($this->publicPath.'/storage'));
        $this->assertFalse($step->shouldRun());
    }

    public function test_a_photo_library_somewhere_else_needs_no_link(): void
    {
        config(['filesystems.disks.media' => ['driver' => 's3'], 'gadya-cms.media.disk' => 'media']);

        $this->assertFalse(app(LinkPublicStorage::class)->shouldRun());
    }

    public function test_the_cms_caches_are_forgotten(): void
    {
        foreach ([SiteContentRepository::CACHE_KEY, RedirectMap::CACHE_KEY, Reviews::CACHE_KEY, PortalMail::CACHE_KEY] as $key) {
            Cache::forever($key, 'from the old release');
        }

        $step = app(ClearCmsCaches::class);

        $this->assertSame('server', $step->phase());
        $this->assertTrue($step->shouldRun());

        $step->run();

        foreach ([SiteContentRepository::CACHE_KEY, RedirectMap::CACHE_KEY, Reviews::CACHE_KEY, PortalMail::CACHE_KEY] as $key) {
            $this->assertFalse(Cache::has($key), "{$key} is forgotten.");
        }
    }

    public function test_connects_runner_runs_them_when_the_installed_connect_has_one(): void
    {
        if (! class_exists('Gadya\Connect\Upgrade\Upgrader')) {
            $this->markTestSkipped('The installed gadya/connect has no gadya:upgrade.');
        }

        Artisan::call('gadya:upgrade', ['--phase' => 'code', '--json' => true], $output = new BufferedOutput);
        $code = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['cms.0.15.0.notifications-table', 'cms.workflow-template'], array_column($code['ran'], 'key'));
        $this->assertNotNull($code['versions']['gadya_cms']);

        Artisan::call('gadya:upgrade', ['--json' => true, '--dry-run' => true], $output = new BufferedOutput);
        $server = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            ['connect.migrate', 'cms.storage-link', 'cms.caches', 'connect.optimize-clear', 'connect.filament-assets'],
            array_column($server['would_run'], 'key'),
        );
    }
}
