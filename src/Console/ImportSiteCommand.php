<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Transfer\SiteImporter;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\confirm;

class ImportSiteCommand extends Command
{
    protected $signature = 'gadya-cms:import
        {path : A .zip or .json written by gadya-cms:export}
        {--replace : Remove this site\'s existing content first}';

    protected $description = 'Import a site exported by gadya-cms:export';

    public function handle(SiteImporter $importer): int
    {
        if ($this->option('replace') && $this->input->isInteractive() && ! confirm('This removes every page, article, redirect and photo record on this site first. Continue?', default: false)) {
            return self::SUCCESS;
        }

        try {
            $counts = $importer->fromFile((string) $this->argument('path'), (bool) $this->option('replace'));
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($counts as $kind => $count) {
            $this->components->twoColumnDetail(ucfirst($kind), (string) $count);
        }

        $this->components->info('Imported. The live site reflects the imported published copies straight away.');

        return self::SUCCESS;
    }
}
