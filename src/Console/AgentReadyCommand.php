<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Seo\AgentReadiness;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class AgentReadyCommand extends Command
{
    protected $signature = 'gadya-cms:agent-ready {--live : Also fetch robots.txt, sitemap.xml and llms.txt from APP_URL}';

    protected $description = 'Score how ready the site is for search engines and AI assistants';

    public function handle(AgentReadiness $readiness): int
    {
        $audit = $readiness->audit();

        $this->components->info("Agent readiness: {$audit['score']} / 100 ({$audit['passed']} of {$audit['total']} checks)");

        foreach ($audit['checks'] as $check) {
            $this->components->twoColumnDetail(($check['passed'] ? '<fg=green>✓</> ' : '<fg=yellow>!</> ').$check['label'], $check['passed'] ? '' : $check['fix']);
        }

        if ($this->option('live')) {
            $this->newLine();

            foreach (['/robots.txt', '/sitemap.xml', '/llms.txt'] as $path) {
                $status = rescue(fn (): int => Http::timeout(10)->get(url($path))->status(), 0, report: false);
                $this->components->twoColumnDetail(url($path), $status === 200 ? '<fg=green>200</>' : "<fg=red>{$status}</>");
            }
        }

        return $audit['score'] >= 70 ? self::SUCCESS : self::FAILURE;
    }
}
