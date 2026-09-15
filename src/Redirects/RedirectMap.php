<?php

namespace Gadya\Cms\Redirects;

use Gadya\Cms\Models\Redirect;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/**
 * Every redirect as one cached array, so answering "is this address
 * redirected?" on every request costs no query.
 */
class RedirectMap
{
    public const CACHE_KEY = 'gadya-cms.redirects';

    public function __construct(private readonly SiteContext $siteContext) {}

    /**
     * @return array{to: string, status: int, id: int}|null
     */
    public function match(string $path): ?array
    {
        return $this->all()[Redirect::normalise($path)] ?? null;
    }

    /**
     * @return array<string, array{to: string, status: int, id: int}>
     */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $siteId = $this->siteContext->id();

            if ($siteId === null) {
                return [];
            }

            try {
                return Redirect::query()
                    ->where('site_id', $siteId)
                    ->get(['id', 'from_path', 'to_path', 'status_code'])
                    ->mapWithKeys(fn (Redirect $redirect): array => [
                        $redirect->from_path => ['to' => $redirect->to_path, 'status' => $redirect->status_code, 'id' => (int) $redirect->getKey()],
                    ])
                    ->all();
            } catch (QueryException) {
                return [];
            }
        });
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
