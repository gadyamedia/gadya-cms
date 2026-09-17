<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Search\SearchConsole;
use Illuminate\Console\Command;
use Throwable;

class FetchSearchConsoleCommand extends Command
{
    protected $signature = 'gadya-cms:search-console {--days=28 : How many days of data to fetch}';

    protected $description = 'Fetch the latest queries and landing pages from Google Search Console';

    public function handle(SearchConsole $console): int
    {
        if (! $console->isConfigured()) {
            $this->components->warn('Search Console is not set up; nothing fetched. Add the property and key under Settings → Search & speed.');

            return self::SUCCESS;
        }

        try {
            $counts = $console->fetch((int) $this->option('days'));
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Fetched {$counts['queries']} queries and {$counts['pages']} pages.");

        return self::SUCCESS;
    }
}
