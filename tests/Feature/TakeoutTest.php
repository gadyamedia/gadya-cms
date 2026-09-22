<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Tests\TestCase;
use ZipArchive;

/**
 * A client who cannot leave has to be kept rather than earned. The
 * takeout is the door: everything she owns, readable without us.
 */
class TakeoutTest extends TestCase
{
    public function test_it_packs_the_words_the_articles_and_the_enquiries_with_a_note_saying_they_are_hers(): void
    {
        $this->publishDocument([
            'pages' => ['about' => ['title' => 'About us', 'type' => 'content', 'body' => 'Twenty years of dentistry in Hove.']],
        ]);
        config(['gadya-cms.seo.site_name' => 'Acme Dental']);

        Post::query()->create([
            'site_id' => 1,
            'title' => 'Looking after a new crown',
            'slug' => 'new-crown',
            'content' => 'Brush gently for the first few days.',
            'status' => 'published',
            'published_at' => now()->subWeek(),
        ]);

        FormSubmission::query()->create([
            'site_id' => 1,
            'form' => 'contact',
            'data' => ['name' => 'Ada', 'email' => 'ada@example.test'],
            'path' => '/contact',
            'status' => FormSubmission::STATUS_NEW,
        ]);

        $path = storage_path('app/takeout-test.zip');
        @unlink($path);

        $this->artisan('gadya-cms:takeout', ['--path' => $path])->assertSuccessful();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);

        $readme = (string) $zip->getFromName('README.md');
        $this->assertStringContainsString('This is yours', $readme);
        $this->assertStringContainsString('we will not make it', $readme, 'The promise not to make leaving difficult is the point.');
        $this->assertStringContainsString('Acme Dental', $readme);

        $this->assertStringContainsString('Twenty years of dentistry in Hove.', (string) $zip->getFromName('pages/about.md'));
        $this->assertStringContainsString('Brush gently', (string) $zip->getFromName('articles/new-crown.md'));

        $csv = (string) $zip->getFromName('enquiries.csv');
        $this->assertStringContainsString('ada@example.test', $csv);
        $this->assertStringContainsString('"sent_at","form","page"', $csv);

        $this->assertNotFalse($zip->locateName('site.json'), 'The machine-readable export is in there for whoever comes next.');

        $zip->close();
        @unlink($path);
    }
}
