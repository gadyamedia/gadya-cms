<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Transfer\Takeout;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Packs up everything the client owns so she can walk away with it.
 */
class TakeoutCommand extends Command
{
    protected $signature = 'gadya-cms:takeout {--path= : Where to write the zip}';

    protected $description = 'Pack the whole site - words, photographs, articles, enquiries - into one zip the client owns';

    public function handle(Takeout $takeout): int
    {
        $path = (string) ($this->option('path') ?: storage_path('app/takeout/'.Str::slug((string) config('app.name')).'-'.now()->format('Y-m-d').'.zip'));

        try {
            $written = $takeout->write($path);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Written to '.$written.' ('.number_format(filesize($written) / 1048576, 1).' MB).');

        return self::SUCCESS;
    }
}
