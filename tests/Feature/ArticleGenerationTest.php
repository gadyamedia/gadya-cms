<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Ai\Agents\ArticleWriter;
use Gadya\Cms\Ai\AiSettings;
use Gadya\Cms\Blog\ContentAudit;
use Gadya\Cms\Blog\GenerateArticle;
use Gadya\Cms\Filament\Pages\ArticleGenerator;
use Gadya\Cms\Jobs\GenerateArticleDraft;
use Gadya\Cms\Models\ArticleGeneration;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Support\SiteContext;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use RuntimeException;

class ArticleGenerationTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function article(): array
    {
        return [
            'title' => 'How to plan a birthday party',
            'slug' => 'how-to-plan-a-birthday-party',
            'meta_title' => 'How to Plan a Birthday Party | Fun On Us',
            'meta_description' => str_repeat('Plan the party. ', 9),
            'excerpt' => 'Everything you need for a party that runs itself.',
            'body_html' => '<h2>Start with the date</h2><p>'.str_repeat('A birthday party is easier than you think. ', 40).'<a href="/about">who we are</a> and <a href="/pricing">what it costs</a>.</p><h2>Then the place</h2><p>Text.</p><h2>Is it hard?</h2><p>No.</p>',
            'hero_alt' => 'Children around a birthday cake',
            'faq' => [
                ['question' => 'How long should it be?', 'answer' => 'Two hours.'],
                ['question' => 'How many guests?', 'answer' => 'Ten.'],
                ['question' => 'Indoors or out?', 'answer' => 'Either.'],
            ],
            'reading_time' => '5 min read',
        ];
    }

    private function configure(): void
    {
        $this->publishDocument();
        app(AiSettings::class)->save(['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'key' => 'sk-ant-123']);
    }

    public function test_the_job_writes_a_draft_and_scores_it(): void
    {
        $this->configure();
        ArticleWriter::fake([$this->article()]);

        $generation = ArticleGeneration::query()->create([
            'site_id' => app(SiteContext::class)->id(),
            'topic' => 'Planning a birthday party',
            'target_keyword' => 'birthday party',
            'requested_by' => $this->editor()->getKey(),
        ]);

        (new GenerateArticleDraft($generation))->handle(app(GenerateArticle::class), app(ContentAudit::class));

        $post = Post::query()->firstOrFail();

        $this->assertSame('draft', $post->status);
        $this->assertSame('ai', $post->source);
        $this->assertSame('how-to-plan-a-birthday-party', $post->slug);
        $this->assertSame('birthday party', $post->target_keyword);
        $this->assertCount(3, $post->faq);
        $this->assertIsInt($post->ai_meta['audit']['score']);
        $this->assertSame('claude-sonnet-5', $post->ai_meta['model']);

        $this->assertSame('completed', $generation->fresh()->status);
        $this->assertTrue($generation->fresh()->post->is($post));

        ArticleWriter::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'birthday party')
            && str_contains($prompt->prompt, '/about'));
    }

    public function test_a_slug_that_is_taken_gets_a_suffix(): void
    {
        $this->configure();
        Post::factory()->create(['slug' => 'how-to-plan-a-birthday-party']);
        ArticleWriter::fake([$this->article()]);

        $generation = ArticleGeneration::query()->create(['site_id' => app(SiteContext::class)->id(), 'topic' => 'Parties']);
        (new GenerateArticleDraft($generation))->handle(app(GenerateArticle::class), app(ContentAudit::class));

        $this->assertSame('how-to-plan-a-birthday-party-2', $generation->fresh()->post->slug);
    }

    public function test_a_failure_is_recorded_on_the_request(): void
    {
        $this->configure();

        $generation = ArticleGeneration::query()->create(['site_id' => app(SiteContext::class)->id(), 'topic' => 'Parties']);
        (new GenerateArticleDraft($generation))->failed(new RuntimeException('The service is down'));

        $this->assertSame('failed', $generation->fresh()->status);
        $this->assertSame('The service is down', $generation->fresh()->failure_reason);
    }

    public function test_the_generator_screen_queues_a_request(): void
    {
        $this->configure();
        Queue::fake();

        Livewire::actingAs($this->editor())
            ->test(ArticleGenerator::class)
            ->fillForm([
                'topic' => 'Planning a birthday party',
                'target_keyword' => 'birthday party',
                'target_location' => 'Brooklyn',
                'search_intent' => 'local',
            ])
            ->call('generate')
            ->assertHasNoFormErrors()
            ->assertNotified('Writing started');

        $generation = ArticleGeneration::query()->firstOrFail();

        $this->assertSame('queued', $generation->status);
        $this->assertSame('Brooklyn', $generation->target_location);
        Queue::assertPushed(GenerateArticleDraft::class, fn (GenerateArticleDraft $job): bool => $job->generation->is($generation));
    }

    public function test_the_generator_refuses_while_a_request_is_running_or_ai_is_not_set_up(): void
    {
        $this->publishDocument();
        Queue::fake();

        Livewire::actingAs($this->editor())
            ->test(ArticleGenerator::class)
            ->fillForm(['topic' => 'Parties', 'search_intent' => 'informational'])
            ->call('generate')
            ->assertNotified('AI is not set up yet');

        app(AiSettings::class)->save(['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'key' => 'sk-ant-123']);
        ArticleGeneration::query()->create(['site_id' => app(SiteContext::class)->id(), 'topic' => 'Running', 'status' => 'processing']);

        Livewire::actingAs($this->editor())
            ->test(ArticleGenerator::class)
            ->fillForm(['topic' => 'Parties', 'search_intent' => 'informational'])
            ->call('generate')
            ->assertNotified('An article is already being written');

        $this->assertDatabaseCount('gadyacms_article_generations', 1);
        Queue::assertNothingPushed();
    }

    public function test_the_generator_screen_shows_recent_requests_with_a_link_to_the_draft(): void
    {
        $this->configure();
        $post = Post::factory()->create();
        ArticleGeneration::query()->create(['site_id' => app(SiteContext::class)->id(), 'topic' => 'A finished one', 'status' => 'completed', 'post_id' => $post->getKey()]);

        Livewire::actingAs($this->editor())
            ->test(ArticleGenerator::class)
            ->assertSee('A finished one')
            ->assertSee('Open the draft');
    }
}
