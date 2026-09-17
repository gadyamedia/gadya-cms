<?php

namespace Gadya\Cms\Http\Controllers;

use Gadya\Cms\Seo\LlmsText;
use Gadya\Cms\Seo\SitemapEntries;
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
            $lines[] = '';
        }

        $lines[] = 'User-agent: *';

        foreach ($disallow as $path) {
            $lines[] = 'Disallow: '.$path;
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
}
