<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Tests\TestCase;
use Gadya\Cms\Upgrade\WorkflowTemplate;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

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
        $this->assertStringContainsString('composer update $PACKAGES -W', $workflow);
        $this->assertStringContainsString('php artisan test', $workflow, 'Nothing is merged without running the site\'s tests.');
        $this->assertStringContainsString('gh pr create', $workflow);
    }

    public function test_the_template_says_its_number_and_takes_what_the_portal_sends(): void
    {
        $template = (string) File::get(WorkflowTemplate::templatePath());
        $workflow = Yaml::parse($template);
        $inputs = $workflow['on']['workflow_dispatch']['inputs'];

        $this->assertStringStartsWith("# gadya-update-template: 4\n", $template, 'The portal reads the number from the first line.');
        $this->assertSame(4, WorkflowTemplate::shipped());
        $this->assertSame('Gadya update ${{ inputs.rollout_id }}', $workflow['run-name'], 'The portal finds its run by the rollout in the name.');
        $this->assertSame(['packages', 'constraint', 'merge', 'rollout_id', 'always_pull_request'], array_keys($inputs));
        $this->assertSame('gadya/cms gadya/connect', $inputs['packages']['default']);
        $this->assertSame('', $inputs['constraint']['default']);
        $this->assertSame(['patch', 'minor', 'never'], $inputs['merge']['options']);
        $this->assertSame('patch', $inputs['merge']['default']);
        $this->assertSame('', $inputs['rollout_id']['default']);
        $this->assertFalse($inputs['always_pull_request']['default']);
    }

    public function test_the_tests_run_in_an_environment_that_can_boot_the_site(): void
    {
        $steps = collect(Yaml::parse((string) File::get(WorkflowTemplate::templatePath()))['jobs']['update']['steps']);
        $names = $steps->pluck('name')->filter()->values()->all();
        $environment = (string) $steps->firstWhere('name', 'Prepare the environment')['run'];

        /* A fresh checkout has no .env, so no app key, and every test that boots the site fails. */
        $this->assertStringContainsString('cp .env.example .env', $environment);
        $this->assertStringContainsString('key:generate', $environment);
        $this->assertStringContainsString('.git/info/exclude', $environment, 'What it makes must never be committed.');

        $this->assertLessThan(array_search('Upgrade steps', $names, true), array_search('Prepare the environment', $names, true));
        $this->assertLessThan(array_search('Update', $names, true), array_search('Prepare the environment', $names, true), 'The site\'s own composer scripts run during the update and need it.');
        $this->assertLessThan(array_search('Tests', $names, true), array_search('Build the assets', $names, true), 'Pages that ask for their stylesheet need it built first.');
    }

    public function test_the_update_runs_as_a_local_environment_and_survives_a_failing_composer_script(): void
    {
        $steps = collect(Yaml::parse((string) File::get(WorkflowTemplate::templatePath()))['jobs']['update']['steps']);
        $update = $steps->firstWhere('name', 'Update');
        $upgrade = $steps->firstWhere('name', 'Upgrade steps');
        $tests = $steps->firstWhere('name', 'Tests');

        /* Laravel Boost, run by a site's own post-update script, only loads in a local environment. */
        foreach ([$update, $upgrade] as $step) {
            $this->assertSame('local', $step['env']['APP_ENV']);
            $this->assertSame('true', $step['env']['APP_DEBUG']);
        }

        $this->assertArrayNotHasKey('env', $tests, 'The tests keep the environment the site\'s phpunit.xml gives them.');
        $this->assertStringContainsString('--no-scripts', (string) $update['run'], 'A script failing after the packages updated must not sink the update.');
        $this->assertStringContainsString('A composer script failed after the update', (string) $update['run']);
    }

    public function test_a_pull_request_the_repository_forbids_does_not_lose_the_update(): void
    {
        $steps = collect(Yaml::parse((string) File::get(WorkflowTemplate::templatePath()))['jobs']['update']['steps']);
        $publish = (string) $steps->firstWhere('name', 'Commit, then push or open a pull request')['run'];

        /* GitHub lets a repository forbid Actions from opening pull requests; the branch must still be pushed, named for the rollout. */
        $this->assertStringContainsString('chore/gadya-update-${ROLLOUT_ID:-', $publish);
        $this->assertLessThan(strpos($publish, 'gh pr create'), strpos($publish, 'git push --force origin "HEAD:${head}"'));
        $this->assertStringContainsString('if url=$(gh pr create', $publish, 'A refused pull request is a warning, not a failed step.');
        $this->assertStringContainsString('the portal opens the pull request', $publish);
    }

    public function test_the_template_runs_the_upgrade_steps_only_where_connect_has_them(): void
    {
        $template = (string) File::get(WorkflowTemplate::templatePath());

        $this->assertStringContainsString('php artisan help gadya:upgrade', $template);
        $this->assertStringContainsString('php artisan gadya:upgrade --phase=code --json', $template);
        $this->assertStringContainsString('php artisan migrate --force --no-interaction || true', $template, 'An older connect gets what the first template did.');
        $this->assertStringContainsString('Gadya-Update: before=${BEFORE:-none} after=${AFTER:-none} tests=${tests} merged=$1 rollout=${ROLLOUT_ID}', $template);
        $this->assertStringContainsString('GITHUB_STEP_SUMMARY', $template);
        $this->assertStringNotContainsString('${{ inputs.', str_replace(['run-name: Gadya update ${{ inputs.rollout_id }}', ': ${{ inputs.'], '', $template), 'Inputs reach the scripts through the environment, never pasted in.');
    }

    public function test_it_leaves_a_workflow_the_site_has_changed_alone(): void
    {
        File::ensureDirectoryExists(dirname($this->workflowPath()));
        File::put($this->workflowPath(), "name: Ours\n");

        $this->artisan('gadya-cms:install', ['--no-admin' => true])->assertSuccessful();

        $this->assertSame("name: Ours\n", File::get($this->workflowPath()));
    }
}
