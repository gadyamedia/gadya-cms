<?php

namespace Gadya\Cms\Http\Controllers;

use Gadya\Cms\Seo\AgentDiscovery;
use Gadya\Cms\Seo\LlmsText;
use Gadya\Cms\Seo\SitemapEntries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class SeoController extends Controller
{
    public function sitemap(SitemapEntries $entries): Response
    {
        return response()
            ->view('gadya-cms::seo.sitemap', ['entries' => $entries->all()])
            ->header('Content-Type', 'application/xml; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * The discovery documents. Each is served with permissive CORS
     * because an agent reading them is, by definition, coming from
     * somewhere else, and they say nothing that is not already public.
     */
    public function aiCatalog(AgentDiscovery $discovery): JsonResponse
    {
        return $this->discoveryJson($discovery->aiCatalog());
    }

    public function apiCatalog(AgentDiscovery $discovery): JsonResponse
    {
        return $this->discoveryJson($discovery->apiCatalog(), 'application/linkset+json');
    }

    public function agentSkills(AgentDiscovery $discovery): JsonResponse
    {
        return $this->discoveryJson($discovery->agentSkills());
    }

    public function mcpServerCard(AgentDiscovery $discovery): JsonResponse
    {
        $card = $discovery->mcpServerCard();

        /*
         * A site that runs no MCP server says so with a 404 rather than an
         * empty card: an agent that finds a card expects it to work.
         */
        abort_if($card === null, 404);

        return $this->discoveryJson($card);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function discoveryJson(array $document, string $type = 'application/json'): JsonResponse
    {
        return response()
            ->json($document, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Content-Type', $type.'; charset=utf-8')
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    public function llms(LlmsText $llms): Response
    {
        return response($llms->render())
            ->header('Content-Type', 'text/markdown; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * Ordinary crawlers get the disallow list; the AI crawlers the site
     * has an opinion about get their own block, so a decision to welcome
     * or refuse them is explicit rather than left to the default.
     */
    public function robots(): Response
    {
        $disallow = (array) config('gadya-cms.seo.robots_disallow', ['/admin', '/cms']);
        $signals = $this->contentSignals();
        $lines = [];

        foreach ((array) config('gadya-cms.seo.ai_crawlers.block', []) as $agent) {
            $lines[] = 'User-agent: '.$agent;
            $lines[] = 'Disallow: /';
            $lines[] = '';
        }

        foreach ((array) config('gadya-cms.seo.ai_crawlers.allow', []) as $agent) {
            $lines[] = 'User-agent: '.$agent;

            foreach ($disallow as $path) {
                $lines[] = 'Disallow: '.$path;
            }

            $lines[] = 'Allow: /';

            if ($signals !== null) {
                $lines[] = $signals;
            }

            $lines[] = '';
        }

        $lines[] = 'User-agent: *';

        foreach ($disallow as $path) {
            $lines[] = 'Disallow: '.$path;
        }

        if ($signals !== null) {
            $lines[] = $signals;
        }

        if (config('gadya-cms.seo.sitemap', true)) {
            $lines[] = '';
            $lines[] = 'Sitemap: '.url('/sitemap.xml');
        }

        if (config('gadya-cms.seo.llms', true)) {
            $lines[] = '# For AI assistants: '.url('/llms.txt');
        }

        return response(implode("\n", $lines)."\n")
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * `Content-Signal: search=yes, ai-input=yes, ai-train=no`, or null when
     * the site declares none.
     */
    private function contentSignals(): ?string
    {
        $signals = array_filter(
            (array) config('gadya-cms.seo.content_signals', []),
            fn ($value, $key): bool => is_string($key) && in_array($value, ['yes', 'no'], true),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($signals === []) {
            return null;
        }

        return 'Content-Signal: '.implode(', ', array_map(fn (string $key, string $value): string => $key.'='.$value, array_keys($signals), $signals));
    }
}
