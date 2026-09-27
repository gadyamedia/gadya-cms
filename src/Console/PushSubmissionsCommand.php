<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Portal\SubmissionPush;
use Illuminate\Console\Command;
use Throwable;

/**
 * The safety net under the queued push: every enquiry from the last week
 * the portal has not yet had, sent now. Covers a queue worker that was
 * down, a sync queue whose one attempt failed, and a portal that was.
 */
class PushSubmissionsCommand extends Command
{
    protected $signature = 'gadya-cms:push-submissions {--limit=100 : The most to send in one run}';

    protected $description = 'Send the Gadya Media portal any recent enquiries it has not had yet';

    public function handle(SubmissionPush $push): int
    {
        if (! $push->enabled()) {
            $this->line('Not sending: the site is not connected to the portal, or pushing is switched off.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($push->unpushed()->limit(max(1, (int) $this->option('limit')))->get() as $submission) {
            try {
                $push->push($submission) ? $sent++ : $failed++;
            } catch (Throwable $exception) {
                $failed++;

                /*
                 * A portal that is down will be down for the next one
                 * too; stop and let the next run try again.
                 */
                $this->warn($exception->getMessage());

                break;
            }
        }

        $this->line("Sent {$sent} ".str('enquiry')->plural($sent).($failed > 0 ? "; {$failed} still to go." : '.'));

        return self::SUCCESS;
    }
}
