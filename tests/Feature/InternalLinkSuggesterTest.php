<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Blog\InternalLinkSuggester;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Tests\TestCase;

class InternalLinkSuggesterTest extends TestCase
{
    public function test_pages_and_articles_that_share_words_with_the_topic_come_first(): void
    {
        $this->publishDocument();
        Post::factory()->published()->create(['title' => 'Pricing explained', 'slug' => 'pricing-explained', 'excerpt' => 'What it costs']);

        $links = app(InternalLinkSuggester::class)->suggest('What does a party cost', 'pricing');

        $this->assertSame('/pricing', $links[0]['url']);
        $this->assertContains('/blog/pricing-explained', array_column($links, 'url'));
        $this->assertNotContains('/old-offer', array_column($links, 'url'), 'Hidden pages are never offered.');
    }
}
