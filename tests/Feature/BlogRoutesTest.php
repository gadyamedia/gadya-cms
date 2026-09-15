<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Models\Post;
use Gadya\Cms\Tests\TestCase;

class BlogRoutesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
    }

    public function test_the_index_lists_live_articles_only(): void
    {
        Post::factory()->published()->create(['title' => 'A live article']);
        Post::factory()->create(['title' => 'A draft article']);
        Post::factory()->scheduled()->create(['title' => 'A scheduled article']);

        $this->get('/blog')
            ->assertOk()
            ->assertSee('A live article')
            ->assertDontSee('A draft article')
            ->assertDontSee('A scheduled article')
            ->assertSee('Now booking summer parties');
    }

    public function test_an_article_renders_in_the_host_layout_with_its_questions_as_structured_data(): void
    {
        $post = Post::factory()->published()->create([
            'slug' => 'foam-parties',
            'title' => 'Foam parties explained',
            'meta_title' => 'Foam parties, explained',
            'content' => '<h2>What happens</h2><p>Foam.</p>',
            'faq' => [['question' => 'Is it messy?', 'answer' => 'Gloriously.']],
        ]);

        $this->get('/blog/foam-parties')
            ->assertOk()
            ->assertSee('<title>Foam parties, explained</title>', false)
            ->assertSee('Foam parties explained')
            ->assertSee('Is it messy?')
            ->assertSee('FAQPage');
    }

    public function test_a_draft_or_scheduled_article_is_not_found_by_a_visitor(): void
    {
        Post::factory()->create(['slug' => 'draft']);
        Post::factory()->scheduled()->create(['slug' => 'later']);

        $this->get('/blog/draft')->assertNotFound();
        $this->get('/blog/later')->assertNotFound();
        $this->get('/blog/never-existed')->assertNotFound();
    }

    public function test_an_editor_with_the_editor_on_can_see_a_draft(): void
    {
        Post::factory()->create(['slug' => 'draft', 'title' => 'Not yet public']);

        $this->actingAs($this->editor())
            ->withSession(['gadya-cms.editing' => true])
            ->get('/blog/draft')
            ->assertOk()
            ->assertSee('Not yet public');
    }
}
