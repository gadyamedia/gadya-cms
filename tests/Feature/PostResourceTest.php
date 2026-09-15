<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Ai\Agents\ArticleWriter;
use Gadya\Cms\Ai\AiSettings;
use Gadya\Cms\Filament\Resources\Posts\Pages\CreatePost;
use Gadya\Cms\Filament\Resources\Posts\Pages\EditPost;
use Gadya\Cms\Filament\Resources\Posts\Pages\ListPosts;
use Gadya\Cms\Filament\Resources\Posts\PostResource;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Tests\TestCase;
use Livewire\Livewire;

class PostResourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
    }

    public function test_the_list_tells_a_draft_from_a_scheduled_and_a_live_article(): void
    {
        $draft = Post::factory()->create(['title' => 'Still writing']);
        $live = Post::factory()->published()->create(['title' => 'Out there']);
        $scheduled = Post::factory()->scheduled()->create(['title' => 'Next week']);

        $this->assertSame('Draft', PostResource::stateLabel($draft));
        $this->assertSame('Live', PostResource::stateLabel($live));
        $this->assertSame('Scheduled', PostResource::stateLabel($scheduled));

        Livewire::actingAs($this->editor())
            ->test(ListPosts::class)
            ->assertCanSeeTableRecords([$draft, $live, $scheduled])
            ->assertSee('Next week');
    }

    public function test_creating_an_article_lands_on_its_edit_screen_with_a_score(): void
    {
        Livewire::actingAs($this->editor())
            ->test(CreatePost::class)
            ->fillForm([
                'title' => 'Our first article',
                'slug' => 'our-first-article',
                'content' => '<h2>Hello</h2><p>Words.</p>',
                'status' => 'draft',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $post = Post::query()->where('slug', 'our-first-article')->firstOrFail();

        $this->assertNotNull($post->site_id);
        $this->assertArrayHasKey('audit', $post->ai_meta);
    }

    public function test_saving_re_scores_the_article(): void
    {
        $post = Post::factory()->create(['target_keyword' => 'foam party', 'title' => 'Nothing relevant']);

        Livewire::actingAs($this->editor())
            ->test(EditPost::class, ['record' => $post->getKey()])
            ->fillForm(['title' => 'Foam party guide', 'meta_title' => 'Foam party guide for parents this summer'])
            ->call('save')
            ->assertHasNoFormErrors();

        $checks = collect($post->fresh()->ai_meta['audit']['checks'])->keyBy('label');

        $this->assertTrue($checks['Keyword in title']['passed']);
        $this->assertTrue($checks['Keyword in meta title']['passed']);
    }

    public function test_rewriting_with_ai_replaces_the_words_but_keeps_the_address(): void
    {
        app(AiSettings::class)->save(['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'key' => 'sk-ant-123']);
        $post = Post::factory()->create(['slug' => 'keep-me', 'title' => 'Old title', 'target_keyword' => 'soft play']);

        ArticleWriter::fake([[
            'title' => 'Soft play for toddlers',
            'slug' => 'soft-play-for-toddlers',
            'meta_title' => 'Soft play for toddlers explained',
            'meta_description' => str_repeat('Soft play. ', 15),
            'excerpt' => 'Why soft play works.',
            'body_html' => '<h2>Why</h2><p>Because.</p>',
            'hero_alt' => 'Toddlers in a soft play area',
            'faq' => [['question' => 'Is it safe?', 'answer' => 'Yes.'], ['question' => 'What age?', 'answer' => '0-5.'], ['question' => 'How long?', 'answer' => 'An hour.']],
            'reading_time' => '3 min read',
        ]]);

        Livewire::actingAs($this->editor())
            ->test(EditPost::class, ['record' => $post->getKey()])
            ->callAction('rewrite', ['topic' => 'Soft play for toddlers'])
            ->assertNotified('Article rewritten')
            ->assertFormSet(['title' => 'Soft play for toddlers']);

        $post->refresh();

        $this->assertSame('keep-me', $post->slug);
        $this->assertSame('ai', $post->source);
        $this->assertCount(3, $post->faq);
        ArticleWriter::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'soft play'));
    }

    public function test_a_visitor_cannot_reach_the_articles(): void
    {
        $this->actingAs($this->visitor())->get(PostResource::getUrl())->assertForbidden();
    }
}
