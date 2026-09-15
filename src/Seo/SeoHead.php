<?php

namespace Gadya\Cms\Seo;

use Gadya\Cms\Content\SiteImage;
use Gadya\Cms\Models\Post;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * The tags in a page's <head> that search engines and link previews read:
 * title, description, canonical address, robots, Open Graph.
 *
 * Every value has a sensible fallback so a page the client has not
 * written a snippet for still gets a title and a description from its
 * own words, and a page she has written one for gets exactly that.
 */
class SeoHead
{
    public function __construct(private readonly SiteImage $images) {}

    /**
     * @param  array<string, mixed>|Post  $subject
     */
    public function render(array|Post $subject, ?string $canonical = null): View
    {
        return view('gadya-cms::seo.head', ['tags' => $this->tags($subject, $canonical)]);
    }

    /**
     * @param  array<string, mixed>|Post  $subject
     * @return array{title: string, description: string, canonical: string, robots: string, image: string|null, type: string, site_name: string}
     */
    public function tags(array|Post $subject, ?string $canonical = null): array
    {
        $seo = $subject instanceof Post
            ? [
                'meta_title' => $subject->meta_title,
                'meta_description' => $subject->meta_description ?: $subject->excerpt,
                'og_image' => $subject->image,
                'canonical' => null,
                'noindex' => ! $subject->isLive(),
            ]
            : (is_array($subject['seo'] ?? null) ? $subject['seo'] : []);

        $fallbackTitle = $subject instanceof Post ? $subject->title : (string) ($subject['title'] ?? $subject['heading'] ?? '');
        $fallbackDescription = $subject instanceof Post
            ? (string) $subject->excerpt
            : (string) ($subject['description'] ?? config('gadya-cms.seo.default_description', ''));

        $title = trim((string) ($seo['meta_title'] ?? '')) ?: $this->withSuffix($fallbackTitle);
        $description = Str::limit(trim(strip_tags((string) (($seo['meta_description'] ?? '') ?: $fallbackDescription))), 300, '');
        $image = (string) (($seo['og_image'] ?? '') ?: ($subject instanceof Post ? '' : ($subject['hero_image'] ?? '')) ?: config('gadya-cms.seo.default_image', ''));

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => trim((string) ($seo['canonical'] ?? '')) ?: ($canonical ?? url()->current()),
            'robots' => ! empty($seo['noindex']) ? 'noindex, nofollow' : 'index, follow',
            'image' => $image !== '' ? $this->images->url($image) : null,
            'type' => $subject instanceof Post ? 'article' : 'website',
            'site_name' => (string) config('gadya-cms.seo.site_name', config('gadya-cms.brand.name', config('app.name'))),
        ];
    }

    private function withSuffix(string $title): string
    {
        $suffix = (string) config('gadya-cms.seo.title_suffix', '');

        if ($title === '' || $suffix === '' || str_contains($title, $suffix)) {
            return $title;
        }

        return $title.$suffix;
    }
}
