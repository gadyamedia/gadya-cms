<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Notifications\AnalyticsDigest;
use Gadya\Cms\Options\Options;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Emails the weekly summary to whoever asked for it on the dashboard.
 * Schedule it weekly; it does nothing when nobody has signed up.
 */
class SendAnalyticsDigestCommand extends Command
{
    protected $signature = 'gadya-cms:analytics-digest {--days=7 : How far back the summary covers}';

    protected $description = 'Email the analytics summary to the people who asked for it';

    public function handle(Options $options): int
    {
        $recipients = array_values(array_filter((array) $options->get('analytics.digest_recipients', []), 'is_string'));

        if ($recipients === []) {
            $this->line('Nobody has asked for the weekly email; nothing sent.');

            return self::SUCCESS;
        }

        Notification::route('mail', $recipients)->notify(new AnalyticsDigest((int) $this->option('days')));

        $this->info('Sent the summary to '.implode(', ', $recipients).'.');

        return self::SUCCESS;
    }
}
