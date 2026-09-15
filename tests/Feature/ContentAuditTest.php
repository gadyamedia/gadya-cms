<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Blog\ContentAudit;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Tests\TestCase;

class ContentAuditTest extends TestCase
{
    public function test_a_well_built_article_scores_highly(): void
    {
        $this->publishDocument();

        $post = Post::factory()->make([
            'title' => 'Foam party guide',
            'slug' => 'foam-party-guide',
            'target_keyword' => 'foam party',
            'meta_title' => 'Foam party guide for parents in New York',
            'meta_description' => 'A foam party is the messiest fun a child can have. Here is how to plan one, what to bring, and how to keep everyone safe and dry afterwards.',
            'excerpt' => 'Everything about foam parties.',
            'image' => 'foam.webp',
            'hero_alt' => 'Children in foam',
            'reading_time' => '6 min read',
            'faq' => [['question' => 'a', 'answer' => 'b'], ['question' => 'c', 'answer' => 'd'], ['question' => 'e', 'answer' => 'f']],
            'content' => '<p>A foam party is '.str_repeat('great fun for everyone involved ', 200).'</p>'
                .'<h2>Planning</h2><p>See <a href="/about">us</a> and <a href="/pricing">prices</a>.</p>'
                .'<h2>Safety</h2><p>Per <a href="https://example.org/foam">the guidance</a>.</p>'
                .'<h2>Is a foam party safe?</h2><h3>Yes</h3><p>Foam party fun.</p>',
        ]);

        $audit = app(ContentAudit::class)->audit($post);

        $this->assertSame(100, $audit['score']);
        $this->assertSame($audit['total'], $audit['passed']);
    }

    public function test_keyword_checks_are_left_out_when_no_keyword_is_set(): void
    {
        $this->publishDocument();

        $audit = app(ContentAudit::class)->audit(Post::factory()->make(['target_keyword' => null]));

        $labels = array_column($audit['checks'], 'label');

        $this->assertNotContains('Keyword in title', $labels);
        $this->assertContains('At least 900 words', $labels);
    }

    public function test_an_h1_in_the_body_fails_the_heading_check(): void
    {
        $this->publishDocument();

        $audit = app(ContentAudit::class)->audit(Post::factory()->make(['content' => '<h1>No</h1><h2>Yes</h2>']));

        $check = collect($audit['checks'])->firstWhere('label', 'Headings in order');

        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('H1', $check['recommendation']);
    }
}
