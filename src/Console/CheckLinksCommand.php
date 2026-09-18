<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Models\BrokenLink;
use Gadya\Cms\Seo\LinkChecker;
use Illuminate\Console\Command;

class CheckLinksCommand extends Command
{
    protected $signature = 'gadya-cms:check-links {--external : Also ask other people\'s servers whether their pages are still there}';

    protected $description = 'Look through the published pages and articles for links that lead nowhere';

    public function handle(LinkChecker $checker): int
    {
        $result = $checker->check((bool) $this->option('external'));

        $this->components->info("Looked at {$result['checked']} links and found {$result['broken']} that lead nowhere.");

        $outstanding = BrokenLink::query()->unresolved()->where('kind', BrokenLink::LINKED)->orderByDesc('last_seen_at')->limit(10)->get();

        if ($outstanding->isNotEmpty()) {
            $this->table(
                ['Link', 'On'],
                $outstanding->map(fn (BrokenLink $link): array => [$link->url, $link->found_on])->all(),
            );
        }

        return self::SUCCESS;
    }
}
