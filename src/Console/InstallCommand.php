<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Services\PublishSiteContent;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Brings a fresh install to a working state: a site row, the document the
 * application ships with loaded as both the draft and the live site, and
 * the images already in the repository indexed in the photo library.
 */
class InstallCommand extends Command
{
    protected $signature = 'gadya-cms:install {--force : Re-seed the document even if content already exists}';

    protected $description = 'Set up the CMS for this application';

    public function handle(
        SiteContext $siteContext,
        SiteContentRepository $repository,
        PublishSiteContent $publish,
    ): int {
        $site = $siteContext->get();

        if ($site === null) {
            $this->error('No site could be created. Run `php artisan migrate` first.');

            return self::FAILURE;
        }

        $this->info("Site [{$site->key}] is ready.");

        if ($site->pages()->exists() && ! $this->option('force')) {
            $this->line('Content already exists; skipping the seed. Pass --force to re-seed.');
        } else {
            $repository->saveDraft($repository->defaults());
            $publish->handle(null, 'Initial content');
            $this->info('Seeded the site document from the application defaults.');
        }

        $this->call(ImportLegacyMediaCommand::class);

        if (Schema::hasTable('site_contents')) {
            $this->line('A legacy [site_contents] table is present. Run `php artisan gadya-cms:import-legacy-content` to bring its content across.');
        }

        return self::SUCCESS;
    }
}
