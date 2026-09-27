<?php

namespace Gadya\Cms\Portal;

use Gadya\Cms\Ai\Agents\ChangeWriter;
use Gadya\Cms\Ai\AiSettings;
use Gadya\Cms\Ai\PortalBrain;
use Gadya\Cms\Ai\Prompter;
use RuntimeException;

/**
 * Asks the site's AI what to change, through whichever route the site
 * already writes with: the client's own key when she has put one in,
 * otherwise Gadya Media's, through the portal - exactly as a photo
 * description or a search snippet is written.
 */
class ChangeProposer
{
    public function __construct(
        private readonly AiSettings $settings,
        private readonly PortalBrain $brain,
        private readonly Prompter $prompter,
    ) {}

    /**
     * @return array{page: string, changes: list<array{field: string, value: string}>, reason: string}
     */
    public function propose(string $prompt): array
    {
        $agent = app(ChangeWriter::class);

        if ($this->settings->isConfigured()) {
            $answer = $this->prompter->prompt($agent, $prompt);

            return $this->clean([
                'page' => $answer['page'] ?? '',
                'changes' => $answer['changes'] ?? [],
                'reason' => $answer['reason'] ?? '',
            ]);
        }

        if (! $this->brain->available()) {
            throw new RuntimeException('This site has no AI to draft the change with. Add a key under Settings → AI, or connect the site to Gadya Media.');
        }

        $text = $this->brain->write(
            $prompt,
            $agent->instructions()."\n\nAnswer with JSON only, no other words: {\"page\": \"...\", \"changes\": [{\"field\": \"...\", \"value\": \"...\"}], \"reason\": \"...\"}",
            words: 900,
        );

        if ($text === null) {
            throw new RuntimeException('The AI did not answer. Try the request again in a few minutes.');
        }

        $json = json_decode((string) preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($text)), true);

        if (! is_array($json)) {
            throw new RuntimeException('The AI answered in a way the site could not read. Try the request again, perhaps in fewer words.');
        }

        return $this->clean($json);
    }

    /**
     * @param  array<string, mixed>  $answer
     * @return array{page: string, changes: list<array{field: string, value: string}>, reason: string}
     */
    private function clean(array $answer): array
    {
        $changes = [];

        foreach ((array) ($answer['changes'] ?? []) as $change) {
            if (is_array($change) && is_string($change['field'] ?? null) && is_string($change['value'] ?? null)) {
                $changes[] = ['field' => trim($change['field']), 'value' => $change['value']];
            }
        }

        return [
            'page' => trim((string) ($answer['page'] ?? '')),
            'changes' => $changes,
            'reason' => trim((string) ($answer['reason'] ?? '')),
        ];
    }
}
