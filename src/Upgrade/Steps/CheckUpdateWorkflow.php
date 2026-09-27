<?php

namespace Gadya\Cms\Upgrade\Steps;

use Gadya\Cms\Upgrade\UpgradeSteps;
use Gadya\Cms\Upgrade\WorkflowTemplate;

/**
 * Says when the repository's update workflow is older than the one this
 * release ships. It never writes the file: GitHub does not let a workflow
 * change workflows, so the portal refreshes it (the check-in tells it
 * which template the site has), or a person copies it.
 */
class CheckUpdateWorkflow
{
    public function key(): string
    {
        return 'cms.workflow-template';
    }

    public function description(): string
    {
        return 'Check the update workflow is the current template';
    }

    public function phase(): string
    {
        return UpgradeSteps::CODE;
    }

    public function shouldRun(): bool
    {
        return (WorkflowTemplate::installed() ?? 0) < WorkflowTemplate::shipped();
    }

    public function run(): string
    {
        $installed = WorkflowTemplate::installed();
        $shipped = WorkflowTemplate::shipped();

        $now = $installed === null ? 'There is no '.WorkflowTemplate::PATH : WorkflowTemplate::PATH." is template {$installed}";

        return "{$now}; gadya/cms ships template {$shipped}. Refresh it from the Gadya portal (the site's Updates), or copy vendor/gadya/cms/resources/github/gadya-update.yml over it. This step does not change it.";
    }
}
