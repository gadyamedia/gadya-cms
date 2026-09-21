<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Models\Fix;
use Gadya\Cms\Models\Media;
use Gadya\Cms\Quality\ApplyFix;
use Gadya\Cms\Quality\Failures;
use Gadya\Cms\Search\PageSpeed;
use Gadya\Cms\Tests\TestCase;
use Gadya\Connect\Models\Connection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Google's check says what is wrong with the site; the CMS says it in
 * words the client understands, and puts right the part it owns - the
 * photos with no description, the pages with nothing under their name in
 * search results - through Gadya Media's key rather than one she has to buy.
 */
class QualityTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function lighthouse(): array
    {
        return ['lighthouseResult' => [
            'categories' => [
                'performance' => ['score' => 0.8],
                'accessibility' => ['score' => 0.8, 'auditRefs' => [['id' => 'image-alt'], ['id' => 'aria-allowed-attr']]],
                'best-practices' => ['score' => 1],
                'seo' => ['score' => 0.9, 'auditRefs' => [['id' => 'meta-description']]],
            ],
            'audits' => [
                'image-alt' => [
                    'score' => 0,
                    'title' => 'Image elements do not have `[alt]` attributes',
                    'description' => 'Informative elements should aim for short, descriptive alternate text. [Learn more](https://web.dev/image-alt/).',
                    'details' => ['items' => [['node' => ['selector' => 'main > img.hero', 'snippet' => '<img class="hero">'], 'explanation' => 'Fix this']]],
                ],
                'aria-allowed-attr' => [
                    'score' => 0,
                    'title' => 'Elements must only use permitted ARIA attributes',
                    'description' => 'Using ARIA attributes in roles where they are prohibited confuses assistive technology.',
                    'details' => ['items' => [['node' => ['selector' => 'nav > div[aria-label]', 'snippet' => '<div aria-label="Menu">']]]],
                ],
                'meta-description' => ['score' => 1, 'title' => 'Document has a meta description'],
                'uses-long-cache-ttl' => ['score' => 0.4, 'scoreDisplayMode' => 'informative', 'title' => 'Serve static assets with an efficient cache policy'],
            ],
        ]];
    }

    private function pair(): Connection
    {
        return Connection::query()->create([
            'site_id' => 7,
            'portal_url' => 'https://portal.test',
            'secret' => 'shhh',
            'site_name' => 'Acme Dental',
        ]);
    }

    public function test_a_check_keeps_what_failed_and_which_element_failed_it(): void
    {
        $this->publishDocument();
        Http::fake([PageSpeed::ENDPOINT.'*' => Http::response($this->lighthouse())]);

        $score = app(PageSpeed::class)->check('http://cms.test/');

        $this->assertCount(2, $score->failures, 'Passing and informative audits are not failures.');

        $alt = collect($score->failures)->firstWhere('id', 'image-alt');
        $this->assertSame('accessibility', $alt['category']);
        $this->assertStringNotContainsString('[Learn more]', $alt['description'], 'Markdown links are stripped for the client.');
        $this->assertSame('main > img.hero', $alt['elements'][0]['selector']);
    }

    public function test_the_things_the_cms_can_fix_come_first_and_say_so_in_plain_english(): void
    {
        $this->publishDocument();
        Http::fake([PageSpeed::ENDPOINT.'*' => Http::response($this->lighthouse())]);
        app(PageSpeed::class)->check('http://cms.test/');

        $failures = app(Failures::class)->all();

        $this->assertSame('image-alt', $failures->first()['id']);
        $this->assertTrue($failures->first()['fixable']);
        $this->assertSame('A photo with no description of what it shows.', $failures->first()['what']);
        $this->assertFalse($failures->last()['fixable'], 'The ARIA one is in the templates, not the words.');
        $brief = app(Failures::class)->brief(app(Failures::class)->forDevelopers()->sole());
        $this->assertStringContainsString('aria-allowed-attr', $brief, 'A developer gets the audit id...');
        $this->assertStringContainsString('nav > div[aria-label]', $brief, '...and the element that failed it.');
    }

    public function test_gadya_describes_the_photos_that_have_none_and_tells_the_client_it_did(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('site-media/cake.webp', 'not-really-a-photo');
        Http::fake(['portal.test/*' => Http::response(['text' => 'Children around a table with a birthday cake'])]);
        $this->pair();

        Media::factory()->create(['disk' => 'public', 'filename' => 'cake.webp', 'path' => 'site-media/cake.webp', 'thumbnail_path' => null, 'alt_text' => null]);

        $result = app(ApplyFix::class)->describePhotos();

        $this->assertSame(1, $result['done']);
        $this->assertSame('Children around a table with a birthday cake', Media::query()->first()->alt_text);

        $fix = Fix::query()->sole();
        $this->assertSame('image-alt', $fix->audit);
        $this->assertSame('gadya', $fix->written_by, 'The client is told Gadya wrote it, not her own key.');
        $this->assertStringContainsString('screen readers', $fix->says());

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('https://portal.test/api/connect/v1/ai', $request->url());
            $this->assertSame('image/webp', $request->data()['images'][0]['mime']);

            return true;
        });
    }

    public function test_a_missing_search_snippet_is_written_into_the_draft_not_onto_the_live_site(): void
    {
        $this->publishDocument([
            'pages' => ['about' => ['title' => 'About', 'heading' => 'Twenty years of dentistry in Hove', 'seo' => ['meta_description' => '']]],
        ]);
        Http::fake(['portal.test/*' => Http::response(['text' => 'A family dental practice in Hove, caring for the same families for twenty years.'])]);
        $this->pair();

        $result = app(ApplyFix::class)->describePages();

        $this->assertSame(1, $result['done']);
        $this->assertSame(
            'A family dental practice in Hove, caring for the same families for twenty years.',
            app(SiteContentRepository::class)->draft()['pages']['about']['seo']['meta_description'],
        );
        $this->assertSame('', app(SiteContentRepository::class)->published()['pages']['about']['seo']['meta_description'], 'Nothing goes live until she publishes.');
        $this->assertSame('meta-description', Fix::query()->sole()->audit);
    }

    public function test_an_unpaired_site_with_no_key_of_its_own_is_left_alone(): void
    {
        Storage::fake('public');
        Media::factory()->create(['disk' => 'public', 'alt_text' => null]);

        $result = app(ApplyFix::class)->describePhotos();

        $this->assertSame(0, $result['done']);
        $this->assertNull(Media::query()->first()->alt_text, 'Better no description than an invented one.');
        $this->assertSame(0, Fix::query()->count());
    }
}
