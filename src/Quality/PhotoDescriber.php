<?php

namespace Gadya\Cms\Quality;

use Gadya\Cms\Ai\Agents\AltTextWriter;
use Gadya\Cms\Ai\AiSettings;
use Gadya\Cms\Ai\PortalBrain;
use Gadya\Cms\Ai\Prompter;
use Gadya\Cms\Models\Fix;
use Gadya\Cms\Models\Media;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Image;
use Throwable;

/**
 * Looks at one photo and writes what it shows, for someone who cannot see
 * it: with AltTextWriter on the client's own key, which can also say "this
 * is decoration", or through Gadya Media's when she has none.
 */
class PhotoDescriber
{
    public function __construct(
        private readonly AiSettings $settings,
        private readonly PortalBrain $brain,
        private readonly Prompter $prompter,
    ) {}

    public function available(): bool
    {
        return $this->settings->isConfigured() || $this->brain->available();
    }

    /**
     * Describe it and save the description. False when nothing could be
     * written - no AI, no file, or a model that did not answer - so the
     * caller can stop rather than burn through the rest.
     */
    public function describe(Media $photo): bool
    {
        $contents = $this->contents($photo);

        if ($contents === null) {
            return false;
        }

        if ($this->settings->isConfigured()) {
            try {
                $answer = $this->prompter->prompt(
                    app(AltTextWriter::class),
                    'Write the alt text for this photograph.',
                    [Image::fromBase64(base64_encode($contents), $photo->mime_type ?: 'image/webp')],
                );
            } catch (Throwable) {
                return false;
            }

            if ((bool) ($answer['decorative'] ?? false)) {
                $photo->forceFill(['alt_text' => '', 'decorative' => true])->save();

                return true;
            }

            return $this->save($photo, (string) ($answer['alt'] ?? ''), 'site');
        }

        $alt = $this->brain->write(
            'Describe this photograph for someone who cannot see it, in one sentence of at most 18 words. Say what is in the picture, plainly. Do not begin with "an image of" or "a photo of", and do not add a full stop.',
            'You write alt text for a small business website. Plain words, no marketing.',
            images: [['data' => base64_encode($contents), 'mime' => $photo->mime_type ?: 'image/webp']],
            words: 20,
        );

        return $alt !== null && $this->save($photo, $alt, 'gadya');
    }

    private function save(Media $photo, string $alt, string $writtenBy): bool
    {
        $written = Str::of($alt)->trim()->trim('."')->limit(160, '')->toString();

        if ($written === '') {
            return false;
        }

        $photo->forceFill(['alt_text' => $written])->save();

        Fix::query()->create([
            'audit' => 'image-alt',
            'subject' => (string) $photo->filename,
            'before' => null,
            'after' => $written,
            'written_by' => $writtenBy,
        ]);

        return true;
    }

    private function contents(Media $photo): ?string
    {
        try {
            $contents = Storage::disk($photo->disk)->get($photo->thumbnail_path ?: $photo->path);
        } catch (Throwable) {
            return null;
        }

        return blank($contents) ? null : (string) $contents;
    }
}
