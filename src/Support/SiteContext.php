<?php

namespace Gadya\Cms\Support;

use Gadya\Cms\Models\Site;
use Illuminate\Database\QueryException;

/**
 * Resolves the site every request works against. The storage layer is
 * multi-site capable, but a single-site install never has to think about
 * it: the site named by `gadya-cms.default_site` is created on first use.
 */
class SiteContext
{
    private ?Site $site = null;

    public function get(): ?Site
    {
        if ($this->site !== null) {
            return $this->site;
        }

        try {
            return $this->site = Site::query()->firstOrCreate(
                ['key' => $this->key()],
                ['name' => (string) config('gadya-cms.brand.name', config('app.name'))],
            );
        } catch (QueryException) {
            return null;
        }
    }

    public function id(): ?int
    {
        return $this->get()?->getKey();
    }

    public function set(Site $site): void
    {
        $this->site = $site;
    }

    public function forget(): void
    {
        $this->site = null;
    }

    private function key(): string
    {
        return (string) config('gadya-cms.default_site', 'default');
    }
}
