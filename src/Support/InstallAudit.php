<?php

namespace Gadya\Cms\Support;

use Filament\Facades\Filament;
use Gadya\Cms\Brand\Favicon;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Mail\SharedSender;
use Gadya\Cms\Privacy\Consent;
use Gadya\Cms\Privacy\TrackerScan;
use Gadya\Cms\Search\SearchConsole;
use Gadya\Cms\Seo\AgentReadiness;
use Gadya\Cms\Upgrade\SectionLoops;
use Gadya\Cms\Upgrade\WorkflowTemplate;
use Gadya\Connect\Models\Connection;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Gate;

/**
 * Everything that stands between an application and the whole of the
 * installed gadya/cms: config keys it has not copied, migrations it has
 * not run, features switched off, jobs not scheduled, directives missing
 * from its templates. Each finding names its fix, so an upgrade can work
 * down the list until it is empty.
 */
class InstallAudit
{
    public const OK = 'ok';

    public const TODO = 'todo';

    public const OPTIONAL = 'optional';

    /**
     * Maps whose keys belong to the site, not the package: their default
     * entries are examples, so a site without them is not missing anything.
     */
    private const FREE_FORM = [
        ...PackageConfig::SITE_OWNED,
        'pages.paths', 'media.variants', 'seo.organization', 'analytics.events', 'maintenance',
    ];

    /**
     * The jobs the package expects the scheduler to run. Those with a time
     * of day run on the business's clock, so the digest arrives at 8am
     * where the business is (`SiteTimezone` is Gadya\Cms\Support\SiteTimezone).
     */
    public const SCHEDULE = [
        'gadya-cms:publish-due' => "Schedule::command('gadya-cms:publish-due')->everyFiveMinutes();",
        'gadya-cms:prune-trash' => "Schedule::command('gadya-cms:prune-trash')->daily();",
        'gadya-cms:check-links' => "Schedule::command('gadya-cms:check-links')->weeklyOn(2, '03:00')->timezone(\\Gadya\\Cms\\Support\\SiteTimezone::current());",
        'gadya-cms:prune-activity' => "Schedule::command('gadya-cms:prune-activity')->weekly();",
        'gadya-cms:prune-analytics' => "Schedule::command('gadya-cms:prune-analytics')->weeklyOn(1, '03:00')->timezone(\\Gadya\\Cms\\Support\\SiteTimezone::current());",
        'gadya-cms:analytics-digest' => "Schedule::command('gadya-cms:analytics-digest')->weeklyOn(1, '08:00')->timezone(\\Gadya\\Cms\\Support\\SiteTimezone::current());",
        'gadya-cms:search-console' => "Schedule::command('gadya-cms:search-console')->dailyAt('05:00')->timezone(\\Gadya\\Cms\\Support\\SiteTimezone::current());",
        'gadya-cms:pagespeed' => "Schedule::command('gadya-cms:pagespeed')->weeklyOn(2, '04:00')->timezone(\\Gadya\\Cms\\Support\\SiteTimezone::current());",
    ];

    public function __construct(private readonly Filesystem $files) {}

    public static function version(): string
    {
        return GadyaCmsPlugin::packageVersion() ?? 'unknown';
    }

    /**
     * @return list<array{group: string, label: string, status: string, fix: string}>
     */
    public function checks(): array
    {
        return [
            ...$this->configChecks(),
            ...$this->migrationChecks(),
            ...$this->featureChecks(),
            ...$this->scheduleChecks(),
            ...$this->templateChecks(),
            ...$this->installChecks(),
        ];
    }

    /**
     * Dot paths the package's config has and the site's config/gadya-cms.php
     * does not - which take the package's defaults (see PackageConfig).
     *
     * @param  array<array-key, mixed>|null  $published  the site's file as written; read from config_path() when null
     * @return list<string>
     */
    public function missingConfigKeys(?array $published = null): array
    {
        $defaults = require $this->packagePath('config/gadya-cms.php');

        $published ??= $this->files->exists(config_path('gadya-cms.php')) ? (array) require config_path('gadya-cms.php') : [];

        return $this->missingKeys($defaults, $published, '');
    }

    /**
     * @return list<array{group: string, label: string, status: string, fix: string}>
     */
    private function configChecks(): array
    {
        if (! $this->files->exists(config_path('gadya-cms.php'))) {
            return [$this->check('Config', 'config/gadya-cms.php is published', false, 'php artisan vendor:publish --tag=gadya-cms-config')];
        }

        $missing = $this->missingConfigKeys();

        /*
         * Informational: the package's defaults fill in whatever the file
         * leaves out, at any depth, so nothing is broken by a missing key.
         */
        $checks = [$this->check(
            'Config',
            'Every key the package reads is in config/gadya-cms.php',
            $missing === [],
            'These take the package\'s defaults, so nothing needs doing. To give one a value for this site, copy it from vendor/gadya/cms/config/gadya-cms.php: '.implode(', ', $missing),
            optional: true,
        )];

        foreach ([
            'seo.site_name' => 'Set seo.site_name to the business name.',
            'seo.organization.telephone' => 'Set seo.organization.telephone (and address, area, same_as).',
            'seo.domains' => 'List every domain the business owns in seo.domains, the site\'s first.',
            'seo.content_signals' => 'Set seo.content_signals, e.g. search=yes, ai-input=yes, ai-train=no.',
        ] as $key => $fix) {
            $checks[] = $this->check('Config', $key.' is filled in', filled(config('gadya-cms.'.$key)), $fix);
        }

        return $checks;
    }

    /**
     * @return list<array{group: string, label: string, status: string, fix: string}>
     */
    private function migrationChecks(): array
    {
        $ran = rescue(fn (): array => app('migrator')->getRepository()->getRan(), [], report: false);

        $pending = collect($this->files->glob($this->packagePath('database/migrations/*.php')))
            ->map(fn (string $path): string => basename($path, '.php'))
            ->reject(fn (string $name): bool => in_array($name, $ran, true))
            ->values()
            ->all();

        return [$this->check('Database', 'Every package migration has run', $pending === [], 'php artisan migrate ('.count($pending).' pending: '.implode(', ', $pending).')')];
    }

    /**
     * @return list<array{group: string, label: string, status: string, fix: string}>
     */
    private function featureChecks(): array
    {
        $plugin = $this->plugin();

        if ($plugin === null) {
            return [$this->check('Features', 'GadyaCmsPlugin is registered on a panel', false, 'Add GadyaCmsPlugin::make() to ->plugins([...]) in the panel provider.')];
        }

        $checks = [];

        foreach ([
            'analytics' => $plugin->hasAnalytics(),
            'team' => $plugin->hasTeam(),
            'brand' => $plugin->hasBrand(),
            'blog' => $plugin->hasBlog(),
            'ai' => $plugin->hasAi(),
            'redirects' => $plugin->hasRedirects(),
            'forms' => $plugin->hasForms(),
            'search' => $plugin->hasSearch(),
            'newsletter' => $plugin->hasNewsletter(),
            'events' => $plugin->hasEvents(),
            'profile' => $plugin->hasProfile(),
            'unsavedChangesAlerts' => $plugin->warnsAboutUnsavedChanges(),
        ] as $switch => $on) {
            /* Off is a choice a site may make, so it is offered, never demanded. */
            $checks[] = $this->check('Features', "Plugin ->{$switch}() is on", $on, "Switched off. To offer it, remove ->{$switch}(false) from GadyaCmsPlugin::make() in the panel provider.", optional: true);
        }

        foreach ([
            'blog.routes' => 'Articles at /'.config('gadya-cms.blog.prefix', 'blog'),
            'events.routes' => "What's on pages and calendar files",
            'site_search.routes' => 'Public search at /search',
            'newsletter.enabled' => 'Newsletter sign-up',
            'analytics.enabled' => 'Visitor figures',
            'seo.sitemap' => 'sitemap.xml',
            'seo.robots' => 'robots.txt',
            'seo.llms' => 'llms.txt',
            'seo.markdown' => 'Markdown for AI assistants',
            'seo.link_headers' => 'Discovery Link headers',
            'seo.discovery' => 'Agent discovery documents (.well-known)',
            'brand.favicon' => 'Browser-tab icon drawn from the logo',
        ] as $key => $label) {
            $checks[] = $this->check('Features', $label.' ('.$key.')', (bool) config('gadya-cms.'.$key, false), "Switched off. To offer it, set {$key} to true in config/gadya-cms.php.", optional: true);
        }

        $checks[] = $this->check('Features', 'Comments on articles (blog.comments.enabled)', (bool) config('gadya-cms.blog.comments.enabled'), 'Only if someone will moderate them: set blog.comments.enabled to true.', optional: true);

        return $checks;
    }

    /**
     * @return list<array{group: string, label: string, status: string, fix: string}>
     */
    private function scheduleChecks(): array
    {
        $scheduled = $this->scheduledCommands();

        /*
         * The analytics and Search Console jobs only matter while those
         * features are on; a site that switched one off need not run them.
         */
        $plugin = $this->plugin();
        $countsVisits = ($plugin?->hasAnalytics() ?? true) && (bool) config('gadya-cms.analytics.enabled', true);
        $readsSearch = $plugin?->hasSearch() ?? true;
        $needed = [
            'gadya-cms:prune-analytics' => $countsVisits,
            'gadya-cms:analytics-digest' => $countsVisits,
            'gadya-cms:search-console' => $readsSearch,
            'gadya-cms:pagespeed' => $readsSearch,
        ];

        $checks = [];

        foreach (self::SCHEDULE as $command => $line) {
            $isNeeded = $needed[$command] ?? true;

            $checks[] = $this->check(
                'Schedule',
                $command.' is scheduled',
                str_contains($scheduled, $command),
                ($isNeeded ? 'Add to routes/console.php: ' : 'Its feature is switched off, so it has nothing to do. If you switch it on, add to routes/console.php: ').$line,
                optional: ! $isNeeded,
            );
        }

        return $checks;
    }

    /**
     * Everything the scheduler is set to run, as one string to search.
     *
     * Laravel only loads `routes/console.php` for console commands, so in a
     * web request - the audit screen in the panel - the schedule is empty.
     * There the files that define it are read instead, so the panel and the
     * command agree about what is scheduled.
     */
    private function scheduledCommands(): string
    {
        $events = collect(rescue(fn (): array => app(Schedule::class)->events(), [], report: false))
            ->map(fn ($event): string => (string) $event->command)
            ->implode("\n");

        if ($this->runningInConsole()) {
            return $events;
        }

        $defined = collect([base_path('routes/console.php'), base_path('bootstrap/app.php')])
            ->filter(fn (string $path): bool => $this->files->exists($path))
            ->map(fn (string $path): string => $this->files->get($path))
            ->implode("\n");

        return $events."\n".$defined;
    }

    /** Overridden in tests, which always run in the console. */
    protected function runningInConsole(): bool
    {
        return app()->runningInConsole();
    }

    /**
     * @return list<array{group: string, label: string, status: string, fix: string}>
     */
    private function templateChecks(): array
    {
        $views = collect($this->files->isDirectory(resource_path('views')) ? $this->files->allFiles(resource_path('views')) : [])
            ->filter(fn ($file): bool => str_ends_with($file->getFilename(), '.blade.php'))
            ->map(fn ($file): string => $file->getContents())
            ->implode("\n");

        return [
            /*
             * A site that switched off the package's sitemap, robots.txt and
             * llms.txt runs its own search setup, head tags included.
             */
            $this->check(
                'Templates',
                '@cmsSeo in the public layout\'s <head>',
                str_contains($views, '@cmsSeo'),
                $this->keepsItsOwnSeo()
                    ? 'The site writes its own head tags. Keep them if that is on purpose, and fill <title> and the description from $page[\'seo\'] so the client can edit them; otherwise use @cmsSeo($page).'
                    : 'Replace hand-written <title>/description tags in the layout\'s <head> with @cmsSeo($page).',
                optional: $this->keepsItsOwnSeo(),
            ),
            $this->check('Templates', '@cmsToolbar before </body>', str_contains($views, '@cmsToolbar'), 'Add @cmsToolbar just before </body> in the public layout.'),
            $this->check('Templates', '@gadyaBuiltBy at the end of the footer', str_contains($views, '@gadyaBuiltBy'), str_contains($views, '<gadya-built-by') ? 'Replace the hand-pasted <gadya-built-by> script and tag with @gadyaBuiltBy; it matches the site\'s ink by itself.' : 'Add @gadyaBuiltBy as the last thing in the footer (bottom right).'),
            $this->check('Templates', '@cmsSearchForm somewhere on the site', str_contains($views, '@cmsSearchForm'), 'Add @cmsSearchForm to the header or footer.', optional: true),
            $this->check('Templates', '@cmsNewsletterForm somewhere on the site', str_contains($views, '@cmsNewsletterForm'), 'Add @cmsNewsletterForm to the footer.', optional: true),
            $this->trackersCheck($views),
            $this->formSectionsCheck(),
        ];
    }

    /**
     * Whether a client's "Form" section is drawn on the page. It is only
     * once the site's section loop hands the package the sections it knows
     * with @cmsSection.
     *
     * @return array{group: string, label: string, status: string, fix: string}
     */
    private function formSectionsCheck(): array
    {
        $loops = app(SectionLoops::class);
        $unwired = collect($loops->find())->reject(fn (array $loop): bool => $loop['wired']);
        $label = 'Form sections are drawn on the page (@cmsSection in the section loop)';

        if (! config('gadya-cms.forms.builder.enabled', true) || ! config('gadya-cms.forms.builder.sections', true)) {
            return $this->check('Templates', $label, true, '');
        }

        if ($unwired->isNotEmpty()) {
            return $this->check('Templates', $label, false, 'Run php artisan gadya:upgrade --phase=code (step cms.form-sections), or add @cmsSection('.$unwired->first()['section'].($unwired->first()['index'] !== null ? ', '.$unwired->first()['index'] : '').') as the first line inside the loop at '.str_replace(base_path().'/', '', $unwired->first()['file']).':'.$unwired->first()['line'].'.');
        }

        if ($loops->anyWired()) {
            return $this->check('Templates', $label, true, '');
        }

        return $this->check(
            'Templates',
            $label,
            false,
            $loops->mentionsSections()
                ? 'The templates loop over $page[\'sections\'] in a way the upgrade cannot change by itself. Add @cmsSection($section, $index) as the first line inside that loop, so a "Form" section a client adds is drawn.'
                : 'No template draws page sections. Where a page should take a form, place <x-gadya-cms::form form="slug" /> instead.',
            optional: ! $loops->mentionsSections(),
        );
    }

    /**
     * @return array{group: string, label: string, status: string, fix: string}
     */
    private function trackersCheck(string $views): array
    {
        $scan = TrackerScan::check($views, rescue(fn (): bool => app(Consent::class)->published()['banner_enabled'], false, report: false));

        return $this->check('Templates', 'Third-party trackers wait for the visitor\'s consent', $scan['passed'], $scan['fix']);
    }

    /**
     * @return list<array{group: string, label: string, status: string, fix: string}>
     */
    private function installChecks(): array
    {
        $published = public_path('css/gadya/cms/gadya-cms-panel.css');
        $readiness = rescue(fn (): int => app(AgentReadiness::class)->audit()['score'], 0, report: false);

        return [
            $this->check('Install', 'Panel stylesheet is published and current', $this->files->exists($published) && $this->files->get($published) === $this->files->get($this->packagePath('resources/css/panel.css')), 'php artisan filament:assets'),
            $this->check('Install', 'Gates manage-content and manage-users are defined', Gate::has('manage-content') && Gate::has('manage-users'), 'Define both gates in a service provider (see docs/installation.md).'),
            $this->check('Install', 'No static public/robots.txt hides the generated one', ! $this->files->exists(public_path('robots.txt')), 'Delete public/robots.txt to use the generated one (on Forge, also remove the nginx location = /robots.txt line), unless the site keeps its own on purpose.', optional: true),
            $this->check(
                'Install',
                'No empty public/favicon.ico hides the browser-tab icon',
                ! (config('gadya-cms.brand.favicon', true) && app(Favicon::class)->emptyPlaceholder()),
                'Run php artisan gadya-cms:favicon --write. public/favicon.ico is Laravel\'s empty placeholder, so browsers get a blank icon; the command replaces it with one drawn from the logo, as a real file the web server can serve.',
            ),
            $this->check('Install', 'No static public/sitemap.xml hides the generated one', ! $this->files->exists(public_path('sitemap.xml')), 'Delete public/sitemap.xml to use the generated one, unless the site keeps its own on purpose.', optional: true),
            $this->check('Install', 'Connected to the Gadya Media portal', Connection::current() !== null, 'In the portal: Sites → Connect a site, then php artisan gadya:connect <code> on the live server.', optional: true),
            $this->check(
                'Install',
                'The site can send email',
                app(SharedSender::class)->enabled() || ! in_array((string) config('mail.default'), ['log', 'array', ''], true),
                'Pair the site with the Gadya Media portal and it sends through Gadya (see docs/email.md), or set MAIL_MAILER and the rest of the mail details in .env for the client\'s own service.',
            ),
            $this->check(
                'Install',
                'A queue carries the email, not the visitor',
                ! app(SharedSender::class)->enabled() || config('queue.default') !== 'sync',
                'Email is sent through Gadya Media, and with QUEUE_CONNECTION=sync a visitor waits for the portal to answer before her form says thank you. Set QUEUE_CONNECTION=database, run php artisan queue:table and migrate, and run a worker.',
            ),
            $this->check(
                'Install',
                'The update workflow is the current template',
                (WorkflowTemplate::installed() ?? 0) >= WorkflowTemplate::shipped(),
                WorkflowTemplate::installed() === null
                    ? 'php artisan gadya-cms:install publishes '.WorkflowTemplate::PATH.', which the Gadya portal runs to update the site.'
                    : 'Refresh it from the Gadya portal, or copy vendor/gadya/cms/resources/github/gadya-update.yml over '.WorkflowTemplate::PATH.'.',
                optional: true,
            ),
            $this->check(
                'Install',
                'The site\'s time zone is set',
                app(SiteTimezone::class)->explicitName() !== null,
                'Set the Time zone under Appearance → Locations (or GADYA_TIMEZONE in .env). Until then dates and times show in '.app(SiteTimezone::class)->name().', which is fine if that is where the business is.',
                optional: true,
            ),
            $this->check(
                'Install',
                'Visitors\' real addresses reach the site',
                ! request()->headers->has('CF-Connecting-IP') || ClientIp::honoursCloudflare(),
                'The site is behind Cloudflare but does not use its CF-Connecting-IP header, so visitors are counted by a Cloudflare address and share one form limit. Set gadya-cms.client_ip.trust_cloudflare to true (GADYA_CMS_TRUST_CLOUDFLARE=true), or configure Laravel\'s trusted proxies.',
                optional: true,
            ),
            $this->check(
                'Install',
                'Google Search Console is connected',
                rescue(fn (): bool => app(SearchConsole::class)->isConfigured(), false, report: false),
                'Optional. Press Connect Google Search Console under Settings → Search & speed to see what people search for on the dashboard (docs/search-and-readiness.md).',
                optional: true,
            ),
            $this->check('Install', 'Boost skills match this version', $this->skillsAreCurrent(), 'php artisan boost:update --discover'),
            $this->check('Install', 'AI readiness score is 80 or more (now '.$readiness.')', $readiness >= 80, 'php artisan gadya-cms:agent-ready lists what to fix; most are page descriptions to write in the panel.', optional: true),
        ];
    }

    private function skillsAreCurrent(): bool
    {
        foreach ($this->files->glob($this->packagePath('resources/boost/skills/*/SKILL.md')) as $skill) {
            $name = basename(dirname($skill));
            $installed = collect(['.claude/skills', '.agents/skills', '.ai/skills', '.github/skills', '.cursor/skills'])
                ->map(fn (string $dir): string => base_path($dir.'/'.$name.'/SKILL.md'))
                ->filter(fn (string $path): bool => $this->files->exists($path));

            $expected = $this->skillText($this->files->get($skill));

            if ($installed->isEmpty() || $installed->contains(fn (string $path): bool => $this->skillText($this->files->get($path)) !== $expected)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A skill's words, without the blank lines some versions of Boost add
     * around lists when they install it - which would otherwise make a
     * current skill look stale.
     */
    private function skillText(string $markdown): string
    {
        return trim((string) preg_replace("/\n{2,}/", "\n", str_replace("\r\n", "\n", $markdown)));
    }

    private function plugin(): ?GadyaCmsPlugin
    {
        foreach (rescue(fn (): array => Filament::getPanels(), [], report: false) as $panel) {
            if ($panel->hasPlugin('gadya-cms')) {
                $plugin = $panel->getPlugin('gadya-cms');

                return $plugin instanceof GadyaCmsPlugin ? $plugin : null;
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $defaults
     * @param  array<mixed>  $actual
     * @return list<string>
     */
    private function missingKeys(array $defaults, array $actual, string $prefix): array
    {
        if (array_is_list($defaults) || in_array(rtrim($prefix, '.'), self::FREE_FORM, true)) {
            return [];
        }

        $missing = [];

        foreach ($defaults as $key => $value) {
            $path = $prefix.$key;

            if (! array_key_exists($key, $actual)) {
                $missing[] = $path;

                continue;
            }

            if (is_array($value) && is_array($actual[$key])) {
                $missing = [...$missing, ...$this->missingKeys($value, $actual[$key], $path.'.')];
            }
        }

        return $missing;
    }

    /**
     * @return array{group: string, label: string, status: string, fix: string}
     */
    private function keepsItsOwnSeo(): bool
    {
        return ! config('gadya-cms.seo.sitemap', true) && ! config('gadya-cms.seo.robots', true) && ! config('gadya-cms.seo.llms', true);
    }

    private function check(string $group, string $label, bool $passed, string $fix, bool $optional = false): array
    {
        return [
            'group' => $group,
            'label' => $label,
            'status' => $passed ? self::OK : ($optional ? self::OPTIONAL : self::TODO),
            'fix' => $passed ? '' : $fix,
        ];
    }

    private function packagePath(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }
}
