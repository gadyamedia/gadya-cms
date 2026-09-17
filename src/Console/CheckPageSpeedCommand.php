<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Search\PageSpeed;
use Illuminate\Console\Command;
use Throwable;

class CheckPageSpeedCommand extends Command
{
    protected $signature = 'gadya-cms:pagespeed
        {--url= : One address to check, instead of the top pages}
        {--limit=5 : How many of the top pages to check}
        {--strategy=mobile : mobile or desktop}';

    protected $description = 'Run Lighthouse on the live site through the PageSpeed Insights API';

    public function handle(PageSpeed $pageSpeed): int
    {
        try {
            $scores = $this->option('url')
                ? collect([$pageSpeed->check((string) $this->option('url'), (string) $this->option('strategy'))])
                : $pageSpeed->checkSite((int) $this->option('limit'), (string) $this->option('strategy'));
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Page', 'Performance', 'Accessibility', 'Best practices', 'SEO', 'LCP'],
            $scores->map(fn ($score): array => [$score->path, $score->performance, $score->accessibility, $score->best_practices, $score->seo, $score->lcp_ms !== null ? round($score->lcp_ms / 1000, 1).'s' : '-'])->all(),
        );

        return self::SUCCESS;
    }
}
