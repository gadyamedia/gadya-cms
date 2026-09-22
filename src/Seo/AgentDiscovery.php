<?php

namespace Gadya\Cms\Seo;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Filament\GadyaCmsPlugin;

/**
 * The machine-readable index of what this site offers an agent: an ARD
 * capability manifest, an RFC 9727 API catalogue, and a skills index.
 *
 * Each document lists only what the site actually serves. A brochure site
 * for a dental practice has pages to read and a form to send; it has no
 * OAuth server, no payment channel and no MCP endpoint, and saying
 * otherwise would send agents to addresses that answer 404 - which is
 * worse for them than an honest absence, and is the same mistake as an
 * accessibility badge nobody earned.
 */
class AgentDiscovery
{
    public function __construct(
        private readonly SiteContentRepository $repository,
    ) {}

    /**
     * ARD (Agentic Resource Discovery): what an agent can do here, with
     * the questions each capability answers, so a registry can index it.
     *
     * @return array<string, mixed>
     */
    public function aiCatalog(): array
    {
        return [
            'specVersion' => '0.1',
            'host' => array_filter([
                'name' => $this->siteName(),
                'url' => url('/'),
                'description' => $this->description(),
                'contact' => config('gadya-cms.seo.organization.email'),
            ]),
            'entries' => array_values($this->capabilities()),
        ];
    }

    /**
     * RFC 9727: the catalogue of addresses that answer with data rather
     * than a page, anchored on the site itself.
     *
     * @return array<string, mixed>
     */
    public function apiCatalog(): array
    {
        $links = [];

        if ($this->has('llms')) {
            $links[] = ['href' => url('/llms.txt'), 'type' => 'text/markdown', 'title' => 'What this site is, for an assistant'];
        }

        if ($this->has('sitemap')) {
            $links[] = ['href' => url('/sitemap.xml'), 'type' => 'application/xml', 'title' => 'Every page on the site'];
        }

        $catalog = ['anchor' => url('/'), 'service-doc' => $links];

        if ($this->has('markdown')) {
            $catalog['service-desc'] = [[
                'href' => url('/llms.txt'),
                'type' => 'text/markdown',
                'title' => 'Every page and article is also served as Markdown with Accept: text/markdown',
            ]];
        }

        if (($mcp = $this->mcpEndpoint()) !== null) {
            $catalog['service-desc'][] = ['href' => $mcp, 'type' => 'application/json', 'title' => 'Model Context Protocol endpoint'];
        }

        return ['linkset' => [array_filter($catalog)]];
    }

    /**
     * Agent Skills Discovery: the things an agent can be told how to do
     * here. Only reading skills are published - nothing that writes, and
     * nothing that would let an agent send an enquiry unattended, because
     * the client is the one who would answer it.
     *
     * @return array<string, mixed>
     */
    public function agentSkills(): array
    {
        $skills = [];

        if ($this->has('markdown')) {
            $skills[] = [
                'name' => 'read-pages-as-markdown',
                'type' => 'documentation',
                'description' => 'Read any page or article on '.$this->siteName().' as clean Markdown by sending Accept: text/markdown.',
                'url' => url('/llms.txt'),
            ];
        }

        if (GadyaCmsPlugin::get()->hasEvents() && config('gadya-cms.events.routes', true)) {
            $skills[] = [
                'name' => 'read-whats-on',
                'type' => 'documentation',
                'description' => 'Read what is coming up at '.$this->siteName().', as a page or as an iCalendar feed.',
                'url' => url('/'.trim((string) config('gadya-cms.events.prefix', 'events'), '/').'.ics'),
            ];
        }

        if (GadyaCmsPlugin::get()->hasSearch() && config('gadya-cms.site_search.routes', true)) {
            $skills[] = [
                'name' => 'search-the-site',
                'type' => 'documentation',
                'description' => 'Search '.$this->siteName().' with ?q= on '.url('/'.trim((string) config('gadya-cms.site_search.prefix', 'search'), '/')).'.',
                'url' => url('/'.trim((string) config('gadya-cms.site_search.prefix', 'search'), '/')),
            ];
        }

        return [
            '$schema' => 'https://agentskills.io/schemas/index-v0.2.0.json',
            'skills' => array_map(fn (array $skill): array => [
                ...$skill,
                /* The digest is of the description an agent will act on. */
                'sha256' => hash('sha256', $skill['description']),
            ], $skills),
        ];
    }

    /**
     * SEP-1649: only where the site really runs an MCP server, which is
     * something an application declares for itself.
     *
     * @return array<string, mixed>|null
     */
    public function mcpServerCard(): ?array
    {
        $endpoint = $this->mcpEndpoint();

        if ($endpoint === null) {
            return null;
        }

        return [
            'serverInfo' => [
                'name' => (string) config('gadya-cms.seo.mcp.name', $this->siteName()),
                'version' => (string) config('gadya-cms.seo.mcp.version', '1.0.0'),
            ],
            'transport' => ['type' => 'http', 'endpoint' => $endpoint],
            'capabilities' => (array) config('gadya-cms.seo.mcp.capabilities', ['tools' => new \stdClass]),
        ];
    }

    /**
     * The Link header values for the home page: where the sitemap, the
     * plain-language description and these catalogues live (RFC 8288,
     * RFC 9727 section 3).
     *
     * @return list<string>
     */
    public function links(): array
    {
        $links = [];

        if ($this->has('llms')) {
            $links[] = '<'.url('/llms.txt').'>; rel="describedby"; type="text/markdown"';
            $links[] = '<'.url('/llms.txt').'>; rel="service-doc"; type="text/markdown"';
        }

        if ($this->has('sitemap')) {
            $links[] = '<'.url('/sitemap.xml').'>; rel="sitemap"; type="application/xml"';
        }

        if ($this->has('discovery')) {
            $links[] = '<'.url('/.well-known/api-catalog').'>; rel="api-catalog"; type="application/linkset+json"';
        }

        return $links;
    }

    /**
     * Everything this site can honestly offer an agent.
     *
     * @return list<array<string, mixed>>
     */
    private function capabilities(): array
    {
        $namespace = $this->namespace();
        $entries = [];

        if ($this->has('llms')) {
            $entries[] = [
                'id' => "urn:air:{$namespace}:content:llms-txt",
                'displayName' => 'What '.$this->siteName().' is',
                'description' => $this->description(),
                'type' => 'text/markdown',
                'url' => url('/llms.txt'),
                'representativeQueries' => [
                    'What does '.$this->siteName().' do?',
                    'Where is '.$this->siteName().'?',
                    'How do I contact '.$this->siteName().'?',
                ],
            ];
        }

        if ($this->has('sitemap')) {
            $entries[] = [
                'id' => "urn:air:{$namespace}:content:sitemap",
                'displayName' => 'Every page on '.$this->siteName(),
                'description' => 'The address of every published page and article.',
                'type' => 'application/xml',
                'url' => url('/sitemap.xml'),
                'representativeQueries' => [
                    'What pages does '.$this->siteName().' have?',
                    'List everything on '.$this->siteName().'.',
                ],
            ];
        }

        if ($this->has('markdown')) {
            $entries[] = [
                'id' => "urn:air:{$namespace}:content:markdown",
                'displayName' => 'Pages as Markdown',
                'description' => 'Any page or article served as clean Markdown when the request asks for text/markdown.',
                'type' => 'text/markdown',
                'url' => url('/'),
                'representativeQueries' => [
                    'Read the '.$this->siteName().' services page.',
                    'Summarise a page from '.$this->siteName().'.',
                ],
            ];
        }

        if (($mcp = $this->mcpEndpoint()) !== null) {
            $entries[] = [
                'id' => "urn:air:{$namespace}:mcp:server",
                'displayName' => $this->siteName().' MCP server',
                'description' => 'Tools for working with this site over the Model Context Protocol.',
                'type' => 'application/json',
                'url' => $mcp,
                'representativeQueries' => ['What tools does '.$this->siteName().' offer?'],
            ];
        }

        return $entries;
    }

    private function mcpEndpoint(): ?string
    {
        $endpoint = config('gadya-cms.seo.mcp.endpoint');

        return filled($endpoint) ? url((string) $endpoint) : null;
    }

    private function has(string $feature): bool
    {
        return (bool) config("gadya-cms.seo.{$feature}", true);
    }

    private function siteName(): string
    {
        return (string) (config('gadya-cms.seo.site_name') ?: config('app.name'));
    }

    /** The site's own words about itself, for the manifest's description. */
    private function description(): string
    {
        $document = $this->repository->published();
        $home = (array) ($document['pages']['home'] ?? []);

        $said = trim((string) ($home['seo']['meta_description'] ?? $home['description'] ?? config('gadya-cms.seo.default_description') ?? ''));

        return $said !== '' ? $said : $this->siteName().'.';
    }

    /** The host, as the ARD identifier wants it. */
    private function namespace(): string
    {
        return (string) (parse_url(url('/'), PHP_URL_HOST) ?: 'localhost');
    }
}
