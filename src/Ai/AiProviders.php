<?php

namespace Gadya\Cms\Ai;

/**
 * The AI services the panel offers, with the models worth suggesting for
 * each. The client chooses from this list; the driver names are the ones
 * Laravel's AI SDK understands, so nothing here has to know how any of
 * them are spoken to.
 */
class AiProviders
{
    /**
     * @return array<string, array{label: string, models: list<string>, needs_url: bool, url: string|null, key_hint: string}>
     */
    public static function all(): array
    {
        return [
            'anthropic' => [
                'label' => 'Anthropic (Claude)',
                'models' => ['claude-sonnet-5', 'claude-opus-5', 'claude-haiku-4-5-20251001'],
                'needs_url' => false,
                'url' => null,
                'key_hint' => 'Starts with sk-ant-',
            ],
            'openai' => [
                'label' => 'OpenAI',
                'models' => ['gpt-5', 'gpt-5-mini', 'gpt-4.1'],
                'needs_url' => false,
                'url' => null,
                'key_hint' => 'Starts with sk-',
            ],
            'gemini' => [
                'label' => 'Google Gemini',
                'models' => ['gemini-2.5-pro', 'gemini-2.5-flash'],
                'needs_url' => false,
                'url' => null,
                'key_hint' => 'From Google AI Studio',
            ],
            'groq' => [
                'label' => 'Groq',
                'models' => ['llama-3.3-70b-versatile', 'openai/gpt-oss-120b'],
                'needs_url' => false,
                'url' => null,
                'key_hint' => 'Starts with gsk_',
            ],
            'mistral' => [
                'label' => 'Mistral',
                'models' => ['mistral-large-latest', 'mistral-medium-latest'],
                'needs_url' => false,
                'url' => null,
                'key_hint' => 'From console.mistral.ai',
            ],
            'deepseek' => [
                'label' => 'DeepSeek',
                'models' => ['deepseek-chat', 'deepseek-reasoner'],
                'needs_url' => false,
                'url' => null,
                'key_hint' => 'Starts with sk-',
            ],
            'xai' => [
                'label' => 'xAI (Grok)',
                'models' => ['grok-4', 'grok-3-mini'],
                'needs_url' => false,
                'url' => null,
                'key_hint' => 'Starts with xai-',
            ],
            'openrouter' => [
                'label' => 'OpenRouter',
                'models' => ['anthropic/claude-sonnet-4.5', 'openai/gpt-5', 'google/gemini-2.5-pro'],
                'needs_url' => false,
                'url' => null,
                'key_hint' => 'Starts with sk-or-',
            ],
            'ollama' => [
                'label' => 'Ollama (self-hosted)',
                'models' => ['llama3.1', 'qwen2.5', 'mistral'],
                'needs_url' => true,
                'url' => 'http://localhost:11434',
                'key_hint' => 'Usually not needed',
            ],
            'openai-compatible' => [
                'label' => 'Other OpenAI-compatible API',
                'models' => [],
                'needs_url' => true,
                'url' => null,
                'key_hint' => 'Whatever the service issued',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_map(fn (array $provider): string => $provider['label'], static::all());
    }

    /**
     * @return list<string>
     */
    public static function modelsFor(?string $provider): array
    {
        return static::all()[$provider]['models'] ?? [];
    }

    public static function needsUrl(?string $provider): bool
    {
        return (bool) (static::all()[$provider]['needs_url'] ?? false);
    }

    public static function exists(?string $provider): bool
    {
        return $provider !== null && array_key_exists($provider, static::all());
    }
}
