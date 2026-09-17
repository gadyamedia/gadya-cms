<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Content\SiteContentRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ExportSiteContentCommand extends Command
{
    protected $signature = 'gadya-cms:export-array {--path= : Where to write the exported array}';

    protected $description = 'Export the published site document to a PHP array file';

    public function handle(SiteContentRepository $repository): int
    {
        $path = (string) ($this->option('path') ?? storage_path('app/site-export.php'));

        File::ensureDirectoryExists(dirname($path));
        File::put($path, '<?php'.PHP_EOL.PHP_EOL.'return '.var_export($repository->published(), true).';'.PHP_EOL);

        $this->info("Site content exported to {$path}");

        return self::SUCCESS;
    }
}
