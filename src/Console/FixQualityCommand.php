<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Ai\PortalBrain;
use Gadya\Cms\Quality\ApplyFix;
use Gadya\Cms\Quality\Failures;
use Illuminate\Console\Command;

/**
 * Puts right what the CMS owns - photos with no description, pages with
 * no search snippet - and prints what is left for a developer.
 */
class FixQualityCommand extends Command
{
    protected $signature = 'gadya-cms:fix
                            {--photos : Only describe the photos}
                            {--pages : Only write the missing search snippets}
                            {--limit=25 : How many of each to do in one run}';

    protected $description = 'Fix what Lighthouse found that the CMS can fix itself';

    public function handle(ApplyFix $fixer, Failures $failures, PortalBrain $brain): int
    {
        if (! $brain->available()) {
            $this->components->error('Nothing can write for this site: it has no AI key of its own and is not paired with the Gadya Media portal. Run `php artisan gadya:connect` or add a key under Settings → AI.');

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        $only = $this->option('photos') || $this->option('pages');

        if (! $only || $this->option('photos')) {
            $result = $fixer->describePhotos($limit);
            $this->components->info("Described {$result['done']} photos, {$result['left']} still without a description.");
        }

        if (! $only || $this->option('pages')) {
            $result = $fixer->describePages($limit);
            $this->components->info("Wrote {$result['done']} search snippets, {$result['left']} pages still without one.");
        }

        $code = $failures->forDevelopers();

        if ($code->isNotEmpty()) {
            $this->newLine();
            $this->components->warn($code->count().' things Lighthouse found live in the templates, not the words:');

            foreach ($code as $failure) {
                $this->line($failures->brief($failure));
                $this->newLine();
            }
        }

        $this->components->info('Nothing is live until the draft is published.');

        return self::SUCCESS;
    }
}
