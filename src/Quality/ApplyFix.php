<?php

namespace Gadya\Cms\Quality;

use Gadya\Cms\Ai\PortalBrain;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Models\Fix;
use Gadya\Cms\Models\Media;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Puts right what the CMS owns: the description of a photo, the sentence
 * under a page's name in search results. Everything is written into the
 * draft, never straight onto the live site - the client reads it and
 * presses Publish, as with everything else she changes.
 */
class ApplyFix
{
    public function __construct(
        private readonly SiteContentRepository $repository,
        private readonly PortalBrain $brain,
    ) {}

    /**
     * Describe every photo that has no description. The model is shown the
     * photo itself, so the words say what is in it.
     *
     * @return array{done: int, left: int}
     */
    public function describePhotos(int $limit = 25): array
    {
        $missing = Media::query()->where(fn ($query) => $query->whereNull('alt_text')->orWhere('alt_text', ''))->get();
        $done = 0;

        foreach ($missing->take($limit) as $photo) {
            $alt = $this->brain->write(
                'Describe this photograph for someone who cannot see it, in one sentence of at most 18 words. Say what is in the picture, plainly. Do not begin with "an image of" or "a photo of", and do not add a full stop.',
                'You write alt text for a small business website. Plain words, no marketing.',
                images: $this->photo($photo),
                words: 20,
            );

            if ($alt === null) {
                break;
            }

            $written = Str::of($alt)->trim()->trim('."')->limit(160, '')->toString();

            $photo->forceFill(['alt_text' => $written])->save();
            $this->record('image-alt', (string) $photo->filename, null, $written);
            $done++;
        }

        return ['done' => $done, 'left' => max(0, $missing->count() - $done)];
    }

    /**
     * Write the sentence that shows under a page's name in search results,
     * for every page missing one, from what the page actually says.
     *
     * @return array{done: int, left: int}
     */
    public function describePages(int $limit = 25): array
    {
        $document = $this->repository->draft();
        $pages = collect($document['pages'] ?? [])
            ->filter(fn ($page): bool => is_array($page) && blank($page['seo']['meta_description'] ?? null));
        $done = 0;

        foreach ($pages->take($limit) as $slug => $page) {
            $description = $this->brain->write(
                'Write the sentence that appears under this page in search results: at most 155 characters, plain, saying what the page offers. Here is the page:'."\n\n".$this->words($page),
                'You write meta descriptions for a small business website. No slogans, no exclamation marks.',
                words: 30,
            );

            if ($description === null) {
                break;
            }

            $written = Str::of($description)->trim()->trim('"')->limit(155, '')->toString();

            $document['pages'][$slug]['seo']['meta_description'] = $written;
            $this->record('meta-description', (string) $slug, null, $written);
            $done++;
        }

        if ($done > 0) {
            $this->repository->saveDraft($document);
        }

        return ['done' => $done, 'left' => max(0, $pages->count() - $done)];
    }

    /** Keep a note of it, so the client is told rather than surprised. */
    private function record(string $audit, string $subject, ?string $before, string $after): void
    {
        Fix::query()->create([
            'audit' => $audit,
            'subject' => $subject,
            'before' => $before,
            'after' => $after,
            'written_by' => $this->brain->throughPortal() ? 'gadya' : 'site',
        ]);
    }

    /**
     * The photograph itself, small enough to send, or nothing when the
     * file has gone missing.
     *
     * @return list<array{data: string, mime: string}>
     */
    private function photo(Media $photo): array
    {
        try {
            $contents = Storage::disk($photo->disk)->get($photo->thumbnail_path ?: $photo->path);
        } catch (Throwable) {
            return [];
        }

        if (blank($contents)) {
            return [];
        }

        return [['data' => base64_encode((string) $contents), 'mime' => $photo->mime_type ?: 'image/webp']];
    }

    /**
     * The words a page holds, flattened, so the model reads the page
     * rather than its shape.
     *
     * @param  array<string, mixed>  $page
     */
    private function words(array $page): string
    {
        $text = [];

        array_walk_recursive($page, function ($value, $key) use (&$text): void {
            if (is_string($value) && strlen($value) > 2 && ! in_array($key, ['type', 'status', 'image', 'hero_image'], true)) {
                $text[] = $value;
            }
        });

        return Str::limit(implode("\n", $text), 2000);
    }
}
