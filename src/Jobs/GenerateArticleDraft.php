<?php

namespace Gadya\Cms\Jobs;

use Gadya\Cms\Blog\ArticleRequest;
use Gadya\Cms\Blog\ContentAudit;
use Gadya\Cms\Blog\GenerateArticle;
use Gadya\Cms\Models\ArticleGeneration;
use Gadya\Cms\Models\Post;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Writes an article in the background and leaves it as an unpublished
 * draft, so the client can queue one and walk away. The generation row
 * narrates progress for the panel, and keeps the reason if it fails.
 */
class GenerateArticleDraft implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 200;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public int $uniqueFor = 600;

    public function __construct(public readonly ArticleGeneration $generation) {}

    public function uniqueId(): string
    {
        return (string) $this->generation->getKey();
    }

    public function handle(GenerateArticle $generate, ContentAudit $audit): void
    {
        $generation = $this->generation->fresh();

        if ($generation === null || $generation->status === ArticleGeneration::STATUS_COMPLETED) {
            return;
        }

        $generation->update([
            'status' => ArticleGeneration::STATUS_PROCESSING,
            'stage' => 'Writing the article',
            'attempts' => $generation->attempts + 1,
            'started_at' => $generation->started_at ?? now(),
        ]);

        $fields = $generate->handle(new ArticleRequest(
            topic: $generation->topic,
            keyword: (string) $generation->target_keyword,
            location: (string) $generation->target_location,
            intent: (string) $generation->search_intent,
        ));

        $generation->update(['stage' => 'Saving the draft']);

        DB::transaction(function () use ($generation, $fields, $audit): void {
            $post = Post::query()->create([
                ...$fields,
                'site_id' => $generation->site_id,
                'status' => Post::STATUS_DRAFT,
                'source' => Post::SOURCE_AI,
                'target_keyword' => $generation->target_keyword,
                'target_location' => $generation->target_location,
                'search_intent' => $generation->search_intent,
                'author_id' => $generation->requested_by,
                'ai_meta' => [...$fields['ai_meta'], 'generation_id' => $generation->getKey()],
            ]);

            $audit->record($post);

            $generation->update([
                'status' => ArticleGeneration::STATUS_COMPLETED,
                'stage' => 'Draft ready to review',
                'post_id' => $post->getKey(),
                'completed_at' => now(),
            ]);
        });
    }

    public function failed(?Throwable $exception): void
    {
        $this->generation->fresh()?->update([
            'status' => ArticleGeneration::STATUS_FAILED,
            'stage' => 'Could not write the article',
            'failure_reason' => mb_substr((string) $exception?->getMessage(), 0, 900),
            'completed_at' => now(),
        ]);
    }
}
