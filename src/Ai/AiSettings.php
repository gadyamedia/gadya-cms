<?php

namespace Gadya\Cms\Ai;

use Gadya\Cms\Options\Options;
use Laravel\Ai\AiManager;

/**
 * Which AI service the site talks to, and in whose voice.
 *
 * The provider, model and key are chosen in the panel rather than in an
 * environment file, so the person running the site can change them
 * without a deploy. They are handed to Laravel's AI SDK at request time
 * as a provider named after this package, so every agent here prompts
 * through the same connection without repeating it.
 */
class AiSettings
{
    public const PROVIDER_NAME = 'gadya-cms';

    public function __construct(private readonly Options $options) {}

    public function provider(): ?string
    {
        $provider = $this->options->get('ai.provider');

        return AiProviders::exists($provider) ? $provider : null;
    }

    public function model(): ?string
    {
        $model = $this->options->get('ai.model');

        return is_string($model) && $model !== '' ? $model : null;
    }

    public function apiKey(): ?string
    {
        return $this->options->getSecret('ai.key');
    }

    public function baseUrl(): ?string
    {
        $url = $this->options->get('ai.url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * A service is usable once it has a provider and a model, and a key
     * unless it is one of the self-hosted ones that do without.
     */
    public function isConfigured(): bool
    {
        $provider = $this->provider();

        if ($provider === null || $this->model() === null) {
            return false;
        }

        if (AiProviders::needsUrl($provider) && $this->baseUrl() === null) {
            return false;
        }

        return $provider === 'ollama' || $this->apiKey() !== null;
    }

    /**
     * @param  array{provider?: string|null, model?: string|null, key?: string|null, url?: string|null}  $data
     */
    public function save(array $data): void
    {
        $this->options->set('ai.provider', $data['provider'] ?? null);
        $this->options->set('ai.model', $data['model'] ?? null);
        $this->options->set('ai.url', $data['url'] ?? null);

        /*
         * A blank key on save means "keep the one I already gave you": the
         * form never shows the stored key back, so a blank field is the
         * common case, not a request to forget it.
         */
        if (array_key_exists('key', $data) && $data['key'] !== null && $data['key'] !== '') {
            $this->options->setSecret('ai.key', $data['key']);
        }
    }

    public function forgetKey(): void
    {
        $this->options->forget('ai.key');
    }

    /**
     * How the site describes itself to the model, so an article reads as
     * though the business wrote it rather than a stranger.
     *
     * @return array{business: string, description: string, audience: string, tone: string, area: string, phone: string, contact_path: string, rules: string}
     */
    public function voice(): array
    {
        $voice = $this->options->get('ai.voice', []);
        $voice = is_array($voice) ? $voice : [];

        return [
            'business' => (string) ($voice['business'] ?? config('gadya-cms.brand.name', config('app.name'))),
            'description' => (string) ($voice['description'] ?? ''),
            'audience' => (string) ($voice['audience'] ?? ''),
            'tone' => (string) ($voice['tone'] ?? 'Warm, clear and confident'),
            'area' => (string) ($voice['area'] ?? ''),
            'phone' => (string) ($voice['phone'] ?? ''),
            'contact_path' => (string) ($voice['contact_path'] ?? '/contact'),
            'rules' => (string) ($voice['rules'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $voice
     */
    public function saveVoice(array $voice): void
    {
        $this->options->set('ai.voice', array_map(fn ($value): string => trim((string) $value), $voice));
    }

    /**
     * Make the chosen service known to the AI SDK for this request, and
     * answer with the provider name an agent should prompt through.
     *
     * @throws AiNotConfigured
     */
    public function register(): string
    {
        if (! $this->isConfigured()) {
            throw new AiNotConfigured('AI is not set up yet. Choose a provider and add a key under Settings → AI.');
        }

        config(['ai.providers.'.self::PROVIDER_NAME => array_filter([
            'driver' => $this->provider(),
            'key' => $this->apiKey() ?? '',
            'url' => $this->baseUrl(),
        ], fn ($value): bool => $value !== null)]);

        /*
         * The SDK caches provider instances by name for the life of the
         * process, so a key changed in the panel would otherwise not be
         * used until the worker restarted.
         */
        app(AiManager::class)->forgetInstance(self::PROVIDER_NAME);

        return self::PROVIDER_NAME;
    }
}
