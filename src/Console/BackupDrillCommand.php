<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Support\BackupDrill;
use Illuminate\Console\Command;

/**
 * Opens the newest backup and proves it could be restored.
 */
class BackupDrillCommand extends Command
{
    protected $signature = 'gadya-cms:backup-drill';

    protected $description = 'Open the newest backup and check that its database dump could actually be restored';

    public function handle(BackupDrill $drill): int
    {
        $result = $drill->run();

        if ($result['passed']) {
            $this->components->info($result['says']);

            return self::SUCCESS;
        }

        $this->components->error($result['says']);

        return self::FAILURE;
    }
}
