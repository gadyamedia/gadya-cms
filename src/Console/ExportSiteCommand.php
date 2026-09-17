<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Transfer\SiteExporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * The whole site as one file. Pair with gadya-cms:import to move a site
 * from a laptop to staging, or to keep a copy before a big change.
 */
class ExportSiteCommand extends Command
{
    protected $signature = 'gadya-cms:export
        {--path= : Where to write; .zip carries photos, anything else is JSON}
        {--with-media : Include the photo files (zip only)}
        {--array : The legacy format: the published document as a PHP array}';

    protected $description = 'Export the site - content, articles, redirects, photos - to one file';

    public function handle(SiteExporter $exporter): int
    {
        if ($this->option('array')) {
            return $this->call(ExportSiteContentCommand::class, ['--path' => $this->option('path')]);
        }

        $withMedia = (bool) $this->option('with-media');
        $path = (string) ($this->option('path') ?: storage_path('app/site-export-'.now()->format('Y-m-d-His').($withMedia ? '.zip' : '.json')));

        try {
            $written = $exporter->write($path, $withMedia);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Site exported to {$written}");

        return self::SUCCESS;
    }
}
