<?php

namespace Gadya\Cms\Localisation;

use Gadya\Cms\Ai\Agents\TranslationWriter;
use Gadya\Cms\Ai\AiNotConfigured;
use Gadya\Cms\Ai\AiSettings;
use Gadya\Cms\Ai\PortalBrain;
use Gadya\Cms\Ai\Prompter;
use Gadya\Cms\Options\Options;
use RuntimeException;

/**
 * Machine translation that cannot damage what it translates.
 *
 * Before any text reaches the model, everything that must come back
 * exactly as it went - HTML tags, link targets, web and email addresses,
 * phone numbers, prices, the business's name and the glossary - is swapped
 * for a numbered marker. The model only ever sees words. A piece that comes
 * back with a marker missing, doubled or invented is discarded and the
 * original kept, so a translation can be incomplete but never broken.
 *
 * Written with the site's own AI key when it has one, and by Gadya Media
 * through the portal otherwise, like every other piece of writing here.
 */
class Translator
{
    public const GLOSSARY_OPTION = 'locales.glossary';

    /** Roughly how much text goes to the model in one request. */
    private const CHUNK_CHARACTERS = 6000;

    public function __construct(
        private readonly AiSettings $settings,
        private readonly PortalBrain $brain,
        private readonly Options $options,
        private readonly Locales $locales,
    ) {}

    public function available(): bool
    {
        return $this->brain->available();
    }

    /**
     * Translate pieces of text, keyed by anything. Only the pieces that
     * came back whole are returned.
     *
     * @param  array<string, string>  $texts
     * @return array<string, string>
     */
    public function translate(array $texts, string $locale): array
    {
        if ($texts === []) {
            return [];
        }

        $masked = [];
        $tokens = [];
        $ids = array_keys($texts);

        foreach (array_values($texts) as $index => $text) {
            [$masked[(string) $index], $tokens[(string) $index]] = $this->mask($text);
        }

        $translated = [];

        foreach ($this->chunks($masked) as $chunk) {
            foreach ($this->ask($chunk, $locale) as $index => $text) {
                $restored = isset($tokens[$index]) ? $this->unmask($text, $tokens[$index]) : null;

                if ($restored !== null) {
                    $translated[(string) $ids[(int) $index]] = $restored;
                }
            }
        }

        return $translated;
    }

    /**
     * The names and terms that are never translated: the business's own
     * name, and whatever the site lists under Languages.
     *
     * @return list<string>
     */
    public function glossary(): array
    {
        $stored = $this->options->get(self::GLOSSARY_OPTION, []);

        $terms = [
            (string) config('gadya-cms.brand.name', ''),
            (string) config('gadya-cms.seo.site_name', ''),
            $this->settings->voice()['business'],
            ...(array) config('gadya-cms.locales.glossary', []),
            ...(is_array($stored) ? $stored : []),
        ];

        $terms = array_values(array_unique(array_filter(array_map(fn ($term): string => trim((string) $term), $terms), fn (string $term): bool => mb_strlen($term) >= 2)));

        /* Longest first, so "Fun On Us Parties" is protected before "Fun On Us". */
        usort($terms, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $terms;
    }

    /**
     * The words the site itself has listed, as the Languages screen edits them.
     *
     * @return list<string>
     */
    public function glossaryOption(): array
    {
        $stored = $this->options->get(self::GLOSSARY_OPTION, []);

        return is_array($stored) ? array_values(array_map('strval', $stored)) : [];
    }

    /**
     * @param  list<string>  $terms
     */
    public function saveGlossary(array $terms): void
    {
        $this->options->set(self::GLOSSARY_OPTION, array_values(array_filter(array_map('trim', $terms))));
    }

    /**
     * Swap everything that must survive untouched for ⟦n⟧ markers.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    public function mask(string $text): array
    {
        $tokens = [];

        $keep = function (string $original) use (&$tokens): string {
            $tokens[] = $original;

            return '⟦'.(count($tokens) - 1).'⟧';
        };

        $patterns = [
            '/<[^>]+>/',
            '/(?<=\]\()[^)\s]+(?=\))/',
            '#\b(?:https?://|www\.)[^\s<>()"\'⟦⟧]*[^\s<>()"\'.,;:!?⟦⟧]#iu',
            '/[^\s@<>()⟦⟧]+@[^\s@<>()⟦⟧]+\.[a-z]{2,}/iu',
            '/(?<!\d)(?:\+?1[\s.-]?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}(?!\d)/',
            '/[$€£]\s?\d[\d,]*(?:\.\d+)?(?:\s?(?:-|–|to)\s?[$€£]?\s?\d[\d,]*(?:\.\d+)?)?/u',
        ];

        foreach ($this->glossary() as $term) {
            $patterns[] = '/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/iu';
        }

        foreach ($patterns as $pattern) {
            $text = (string) preg_replace_callback($pattern, fn (array $match): string => $keep($match[0]), $text);
        }

        return [$text, $tokens];
    }

    /**
     * Put the originals back, or null when the markers did not all come
     * back exactly once.
     *
     * @param  array<int, string>  $tokens
     */
    public function unmask(string $text, array $tokens): ?string
    {
        if (trim($text) === '') {
            return null;
        }

        foreach (array_keys($tokens) as $index) {
            if (substr_count($text, '⟦'.$index.'⟧') !== 1) {
                return null;
            }
        }

        if (preg_match_all('/⟦\d+⟧/u', $text) !== count($tokens)) {
            return null;
        }

        /* Replaced from the highest number down, so ⟦1⟧ never eats part of ⟦12⟧. */
        for ($index = count($tokens) - 1; $index >= 0; $index--) {
            $text = str_replace('⟦'.$index.'⟧', $tokens[$index], $text);
        }

        return $text;
    }

    /**
     * @param  array<string, string>  $masked
     * @return list<array<string, string>>
     */
    private function chunks(array $masked): array
    {
        $chunks = [];
        $current = [];
        $size = 0;

        foreach ($masked as $id => $text) {
            if ($current !== [] && $size + mb_strlen($text) > self::CHUNK_CHARACTERS) {
                $chunks[] = $current;
                $current = [];
                $size = 0;
            }

            $current[$id] = $text;
            $size += mb_strlen($text);
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * @param  array<string, string>  $chunk
     * @return array<string, string>
     */
    private function ask(array $chunk, string $locale): array
    {
        $language = $this->locales->englishName($locale);
        $agent = app(TranslationWriter::class)->into($language, $this->glossary());

        $items = array_map(fn (string $id, string $text): array => ['id' => $id, 'text' => $text], array_keys($chunk), $chunk);
        $prompt = "Translate each item's text into {$language}.\n\nITEMS:\n".json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if ($this->settings->isConfigured()) {
            $response = app(Prompter::class)->prompt($agent, $prompt);
            $translations = $response['translations'] ?? [];
        } elseif ($this->brain->available()) {
            $answer = $this->brain->write(
                $prompt."\n\nAnswer with JSON only, in the form {\"translations\": [{\"id\": \"0\", \"text\": \"...\"}]}.",
                $agent->instructions(),
                words: (int) min(12000, max(200, str_word_count(implode(' ', $chunk)) * 2)),
            );

            $translations = $this->decode($answer);
        } else {
            throw new AiNotConfigured('AI is not set up yet. Choose a provider and add a key under Settings → AI.');
        }

        if (! is_iterable($translations)) {
            throw new RuntimeException('The translation came back in a shape that could not be read.');
        }

        $answered = [];

        foreach ($translations as $item) {
            if (is_array($item) && isset($item['id'], $item['text']) && array_key_exists((string) $item['id'], $chunk)) {
                $answered[(string) $item['id']] = (string) $item['text'];
            }
        }

        return $answered;
    }

    /**
     * @return list<mixed>
     */
    private function decode(?string $answer): array
    {
        if ($answer === null) {
            throw new RuntimeException('Nobody could translate it just now. Try again in a few minutes.');
        }

        $json = substr($answer, (int) strpos($answer, '{'), strrpos($answer, '}') - (int) strpos($answer, '{') + 1);
        $decoded = json_decode($json, true);

        return is_array($decoded) && is_array($decoded['translations'] ?? null) ? $decoded['translations'] : [];
    }
}
