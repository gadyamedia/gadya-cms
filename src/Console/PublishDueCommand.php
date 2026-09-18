<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Services\SchedulePublish;
use Illuminate\Console\Command;

class PublishDueCommand extends Command
{
    protected $signature = 'gadya-cms:publish-due';

    protected $description = 'Publish the draft if a publish was scheduled for now or earlier';

    public function handle(SchedulePublish $schedule): int
    {
        $at = $schedule->at();

        if ($at === null) {
            $this->line('No publish is scheduled.');

            return self::SUCCESS;
        }

        if (! $schedule->publishIfDue()) {
            $this->line('A publish is scheduled for '.$at->format('D j M Y, g:ia').'; not yet.');

            return self::SUCCESS;
        }

        $this->components->info('The site is live as of now.');

        return self::SUCCESS;
    }
}
