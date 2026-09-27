<?php

namespace Gadya\Cms\Upgrade\Steps;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Mail\PortalMail;
use Gadya\Cms\Portal\Reviews;
use Gadya\Cms\Redirects\RedirectMap;
use Gadya\Cms\Upgrade\UpgradeSteps;
use Illuminate\Support\Facades\Cache;

/**
 * Forgets what the CMS keeps cached from the release before - the
 * published site in every language, the redirect map, the portal's
 * reviews and mail status - by its own keys, so a new release never
 * serves a document shaped by the old one, even where the application
 * cache is not cleared as a whole.
 */
class ClearCmsCaches
{
    public function __construct(
        private readonly SiteContentRepository $content,
        private readonly RedirectMap $redirects,
    ) {}

    public function key(): string
    {
        return 'cms.caches';
    }

    public function description(): string
    {
        return 'Forget the CMS\'s cached site, redirects, reviews and mail status';
    }

    public function phase(): string
    {
        return UpgradeSteps::SERVER;
    }

    public function shouldRun(): bool
    {
        return true;
    }

    public function run(): string
    {
        $this->content->flushPublishedCache();
        $this->redirects->flush();
        Cache::forget(Reviews::CACHE_KEY);
        Cache::forget(PortalMail::CACHE_KEY);

        return 'Forgot the cached site, redirects, reviews and mail status.';
    }
}
