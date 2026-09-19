<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Support\InstallAudit;
use Illuminate\Console\Command;

class AuditCommand extends Command
{
    protected $signature = 'gadya-cms:audit {--json : Machine-readable output, for an upgrade run by an agent}';

    protected $description = 'List what this application has not yet taken up from the installed gadya/cms: config, migrations, features, schedule, templates';

    public function handle(InstallAudit $audit): int
    {
        $checks = $audit->checks();
        $todo = collect($checks)->where('status', InstallAudit::TODO)->count();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'version' => InstallAudit::version(),
                'todo' => $todo,
                'checks' => $checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $todo === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->components->info('gadya/cms '.InstallAudit::version().': '.($todo === 0 ? 'everything is taken up' : $todo.' to do'));

        foreach (collect($checks)->groupBy('group') as $group => $rows) {
            $this->components->twoColumnDetail('<options=bold>'.$group.'</>');

            foreach ($rows as $row) {
                $mark = match ($row['status']) {
                    InstallAudit::OK => '<fg=green>✓</>',
                    InstallAudit::OPTIONAL => '<fg=gray>○</>',
                    default => '<fg=yellow>!</>',
                };

                $this->components->twoColumnDetail($mark.' '.$row['label'], $row['fix']);
            }
        }

        return $todo === 0 ? self::SUCCESS : self::FAILURE;
    }
}
