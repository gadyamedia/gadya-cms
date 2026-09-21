<?php

namespace Gadya\Cms\Ai;

use Gadya\Cms\Ai\Agents\ShortWriter;
use Gadya\Cms\Mail\SharedSender;
use Gadya\Connect\Portal\PortalClient;
use Laravel\Ai\Files\Image;
use Throwable;

/**
 * Short pieces of writing asked of Gadya Media rather than of a model
 * this site pays for: the alt text for a photo, a description for a page.
 *
 * The key lives in the portal, never on a client's server, and the portal
 * counts what each site asks for and can stop a site on its own. A client
 * who has put her own key in under Settings → AI is asked nothing here:
 * her key writes her articles, as it always did.
 */
class PortalBrain
{
    public const PATH = '/api/connect/v1/ai';

    public function __construct(
        private readonly PortalClient $portal,
        private readonly SharedSender $sender,
        private readonly AiSettings $settings,
    ) {}

    /** Whether anything can write for this site at all. */
    public function available(): bool
    {
        return $this->settings->isConfigured() || $this->sender->connection() !== null;
    }

    /** Whether the writing would be done by Gadya rather than the site's own key. */
    public function throughPortal(): bool
    {
        return ! $this->settings->isConfigured() && $this->sender->connection() !== null;
    }

    /**
     * The answer, or null when nobody could write it - a site with no key
     * and no pairing, or a portal that did not answer. A caller that gets
     * null leaves the text alone rather than writing something made up.
     *
     * @param  list<array{data: string, mime: string}>  $images  Photos the model should look at, base64 encoded
     */
    public function write(string $prompt, ?string $system = null, array $images = [], int $words = 40): ?string
    {
        if ($this->settings->isConfigured()) {
            return $this->writeHere($prompt, $system, $images);
        }

        return $this->writeThere($prompt, $system, $images, $words);
    }

    /** With the client's own key, through the same service her articles use. */
    private function writeHere(string $prompt, ?string $system, array $images): ?string
    {
        try {
            $agent = app(ShortWriter::class)->guidedBy($system ?? 'You write short, plain text for a small business website.');

            $attachments = array_map(fn (array $image) => Image::fromBase64($image['data'], $image['mime']), $images);

            $text = trim((string) app(Prompter::class)->prompt($agent, $prompt, $attachments)->text);
        } catch (Throwable) {
            return null;
        }

        return $text === '' ? null : $text;
    }

    /** With Gadya Media's key, counted and rate-limited by the portal. */
    private function writeThere(string $prompt, ?string $system, array $images, int $words): ?string
    {
        $connection = $this->sender->connection();

        if ($connection === null) {
            return null;
        }

        try {
            $response = $this->portal->send($connection, 'POST', self::PATH, array_filter([
                'prompt' => $prompt,
                'system' => $system,
                'images' => $images,
                'words' => $words,
            ]));
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $text = trim((string) $response->json('text', ''));

        return $text === '' ? null : $text;
    }
}
