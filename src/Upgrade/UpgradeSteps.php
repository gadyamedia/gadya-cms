<?php

namespace Gadya\Cms\Upgrade;

use Gadya\Cms\Upgrade\Steps\CheckUpdateWorkflow;
use Gadya\Cms\Upgrade\Steps\ClearCmsCaches;
use Gadya\Cms\Upgrade\Steps\CreateNotificationsTable;
use Gadya\Cms\Upgrade\Steps\LinkPublicStorage;

/**
 * What an upgrade of this package does by itself, run by gadya/connect's
 * `php artisan gadya:upgrade`: `code` steps change the repository (in CI,
 * before the tests), `server` steps run on the live site after the deploy.
 *
 * connect finds them by their tag and calls five methods - `key()`,
 * `description()`, `phase()`, `shouldRun()` and `run()` - so, like the
 * remote commands, there is no compile-time tie to any connect release,
 * and a connect without the runner simply never asks for them. Each step
 * checks for itself whether it still has anything to do, so they can run
 * after every release. A key never changes once released.
 */
final class UpgradeSteps
{
    public const TAG = 'gadya-connect.upgrade-steps';

    public const CODE = 'code';

    public const SERVER = 'server';

    /** @var list<class-string> In the order they run within their phase. */
    public const STEPS = [
        CreateNotificationsTable::class,
        CheckUpdateWorkflow::class,
        LinkPublicStorage::class,
        ClearCmsCaches::class,
    ];
}
