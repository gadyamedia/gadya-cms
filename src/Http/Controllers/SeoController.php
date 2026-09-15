<?php

namespace Gadya\Cms\Http\Controllers;

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

    public function robots(): Response
    {
        $lines = ['User-agent: *'];

        foreach ((array) config('gadya-cms.seo.robots_disallow', ['/admin', '/cms']) as $path) {
            $lines[] = 'Disallow: '.$path;
        }

        if (config('gadya-cms.seo.sitemap', true)) {
            $lines[] = '';
            $lines[] = 'Sitemap: '.url('/sitemap.xml');
        }

        return response(implode("\n", $lines)."\n")
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
