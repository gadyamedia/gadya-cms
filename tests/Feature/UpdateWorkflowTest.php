<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\File;

class UpdateWorkflowTest extends TestCase
{
    private function workflowPath(): string
    {
        return base_path('.github/workflows/gadya-update.yml');
    }

    protected function tearDown(): void
    {
        File::delete($this->workflowPath());

        parent::tearDown();
    }

    public function test_installing_publishes_the_workflow_the_portal_runs(): void
    {
        File::delete($this->workflowPath());

        $this->artisan('gadya-cms:install', ['--no-admin' => true])->assertSuccessful();

        $this->assertFileExists($this->workflowPath());

        $workflow = (string) File::get($this->workflowPath());

        $this->assertStringContainsString('workflow_dispatch', $workflow, 'The portal runs it by dispatching it.');
        $this->assertStringContainsString('composer update ${{ inputs.packages }}', $workflow);
        $this->assertStringContainsString('php artisan test', $workflow, 'Nothing is merged without running the site\'s tests.');
        $this->assertStringContainsString('gh pr create', $workflow);
    }

    public function test_it_leaves_a_workflow_the_site_has_changed_alone(): void
    {
        File::ensureDirectoryExists(dirname($this->workflowPath()));
        File::put($this->workflowPath(), "name: Ours\n");

        $this->artisan('gadya-cms:install', ['--no-admin' => true])->assertSuccessful();

        $this->assertSame("name: Ours\n", File::get($this->workflowPath()));
    }
}
