<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Notifications\DriftDigest;
use Gadya\Cms\Options\Options;
use Gadya\Cms\Quality\Drift;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Emails what has gone out of date, to whoever gets the weekly summary.
 * Schedule it fortnightly; it sends nothing when there is nothing to say.
 */
class SendDriftDigestCommand extends Command
{
    protected $signature = 'gadya-cms:drift-digest {--show : Print the findings instead of sending them}';

    protected $description = 'Email what has quietly gone out of date on the site';

    public function handle(Drift $drift, Options $options): int
    {
        $findings = $drift->findings();

        if ($findings->isEmpty()) {
            $this->line('Nothing has drifted; nothing sent.');

            return self::SUCCESS;
        }

        if ($this->option('show')) {
            foreach ($findings as $finding) {
                $this->components->twoColumnDetail($finding['says'], $finding['urgency']);
                $this->line('  '.$finding['does']);
            }

            return self::SUCCESS;
        }

        $recipients = array_values(array_filter((array) $options->get('analytics.digest_recipients', []), 'is_string'));

        if ($recipients === []) {
            $this->line('Nobody has asked for the summary email; nothing sent.');

            return self::SUCCESS;
        }

        Notification::route('mail', $recipients)->notify(new DriftDigest);

        $this->components->info('Sent '.$findings->count().' findings to '.count($recipients).' '.str('person')->plural(count($recipients)).'.');

        return self::SUCCESS;
    }
}
