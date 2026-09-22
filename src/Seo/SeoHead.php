<?php

namespace Gadya\Cms\Seo;

use Gadya\Cms\Brand\Favicon;
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
        return view('gadya-cms::seo.head', [
            'tags' => $this->tags($subject, $canonical),
            'structured' => $this->structuredData($subject, $canonical),
            /* Drawn from the logo, and empty on a site with its own icon. */
            'icons' => rescue(fn (): array => app(Favicon::class)->tags(), [], report: false),
        ]);
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

        $written = trim((string) ($seo['meta_title'] ?? ''));

        /*
         * A written title is used as written - unless the site asks for its
         * suffix on every title, as a local business does when the town
         * belongs in each one ("Menu | Manalapan, NJ").
         */
        $title = $written !== ''
            ? (config('gadya-cms.seo.suffix_written_titles', false) ? $this->withSuffix($written) : $written)
            : $this->withSuffix($fallbackTitle);
        $description = Str::limit(trim(strip_tags((string) (($seo['meta_description'] ?? '') ?: $fallbackDescription))), 300, '');
        $image = (string) (($seo['og_image'] ?? '') ?: ($subject instanceof Post ? '' : ($subject['hero_image'] ?? '')) ?: config('gadya-cms.seo.default_image', ''));

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => trim((string) ($seo['canonical'] ?? '')) ?: ($canonical ?? url()->current()),
            /* Large previews in search and Discover, which Google asks to be told. */
            'robots' => ! empty($seo['noindex']) ? 'noindex, nofollow' : 'index, follow, max-image-preview:large',
            'image' => $image !== '' ? $this->imageUrl($image) : null,
            'type' => $subject instanceof Post ? 'article' : 'website',
            'site_name' => (string) config('gadya-cms.seo.site_name', config('gadya-cms.brand.name', config('app.name'))),
        ];
    }

    /**
     * What the page is, in the vocabulary machines share: the organisation
     * and its website on every page, and the article itself on an article.
     * The FAQ block on an article is rendered by its template.
     *
     * @param  array<string, mixed>|Post  $subject
     * @return list<array<string, mixed>>
     */
    public function structuredData(array|Post $subject, ?string $canonical = null): array
    {
        $tags = $this->tags($subject, $canonical);
        $organisation = (array) config('gadya-cms.seo.organization', []);
        $logo = (string) config('gadya-cms.brand.logo', '');

        $organisationNode = array_filter([
            '@type' => (string) ($organisation['type'] ?? 'LocalBusiness'),
            '@id' => $this->organisationId(),
            'name' => $tags['site_name'],
            'url' => url('/'),
            'logo' => $logo !== '' ? $this->images->url($logo) : null,
            'telephone' => $organisation['telephone'] ?? null,
            'email' => $organisation['email'] ?? null,
            'address' => $organisation['address'] ?? null,
            'areaServed' => $organisation['area'] ?? null,
            'sameAs' => array_values(array_filter((array) ($organisation['same_as'] ?? []))) ?: null,
        ], fn ($value): bool => $value !== null && $value !== []);

        /*
         * A site that describes its own business - a restaurant with its
         * hours and menu, say - switches ours off, so search engines are
         * not handed two businesses with the same name. Articles still get
         * their node, pointing at the site's own business by its anchor.
         */
        $nodes = config('gadya-cms.seo.organization_schema', true) ? [
            ['@context' => 'https://schema.org', ...$organisationNode],
            ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => $tags['site_name'], 'url' => url('/'), 'publisher' => ['@id' => $this->organisationId()]],
        ] : [];

        if ($subject instanceof Post) {
            $nodes[] = array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'headline' => $subject->title,
                'description' => $tags['description'],
                'image' => $tags['image'],
                'datePublished' => $subject->published_at?->toIso8601String(),
                'dateModified' => $subject->updated_at?->toIso8601String(),
                'mainEntityOfPage' => $tags['canonical'],
                'author' => ['@id' => $this->organisationId()],
                'publisher' => ['@id' => $this->organisationId()],
            ], fn ($value): bool => $value !== null);
        }

        return $nodes;
    }

    /** The business's node, as the site's own structured data names it. */
    private function organisationId(): string
    {
        $anchor = trim((string) config('gadya-cms.seo.organization.anchor', 'organization'), '#') ?: 'organization';

        return url('/').'#'.$anchor;
    }

    /**
     * A photo-library name, or a path under public/ or a full address
     * given as they are: a share card made for social previews usually
     * lives beside the site's other images, not in the library.
     */
    private function imageUrl(string $image): string
    {
        if (preg_match('#^https?://#i', $image)) {
            return $image;
        }

        return str_starts_with($image, '/') ? url($image) : $this->images->url($image);
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
