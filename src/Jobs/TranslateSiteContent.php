<?php

namespace Gadya\Cms\Jobs;

use Gadya\Cms\Localisation\Locales;
use Gadya\Cms\Localisation\TranslateContent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Machine-translates a few pieces of the site into the draft of another
 * language, marked for a person to review. One piece that cannot be
 * translated is noted and skipped rather than failing the others.
 */
class TranslateSiteContent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    /**
     * @param  list<string>  $keys
     */
    public function __construct(
        public readonly array $keys,
        public readonly string $locale,
        public readonly int|string|null $requestedBy = null,
    ) {}

    public function handle(TranslateContent $content, Locales $locales): void
    {
        $locales->inDefault(function () use ($content): void {
            foreach ($this->keys as $key) {
                try {
                    $content->translate($key, $this->locale);
                } catch (Throwable $exception) {
                    Log::warning("Gadya CMS could not translate [{$key}] into [{$this->locale}]: {$exception->getMessage()}");

                    if ($this->keys === [$key]) {
                        throw $exception;
                    }
                }
            }
        });
    }
}
