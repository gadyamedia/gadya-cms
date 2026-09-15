<?php

namespace Gadya\Cms\Options;

use Gadya\Cms\Models\Option;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;

/**
 * Site configuration that is not content.
 *
 * The site document is for what visitors see, and everything in it is
 * drafted, published and kept in revisions. An API key or a list of digest
 * recipients is none of those things - it must never be copied into a
 * revision snapshot, and "publishing" it means nothing - so it is kept
 * here, one row per key, read once per request.
 */
class Options
{
    /** @var array<string, mixed>|null */
    private ?array $loaded = null;

    public function __construct(private readonly SiteContext $siteContext) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $siteId = $this->siteContext->id();

        if ($siteId === null) {
            return;
        }

        Option::query()->updateOrCreate(
            ['site_id' => $siteId, 'key' => $key],
            ['value' => $value === null ? null : ['value' => $value]],
        );

        $this->loaded = null;
    }

    /**
     * A value nobody should be able to read out of a database dump: stored
     * encrypted with the application key, and decrypted only on read.
     */
    public function setSecret(string $key, ?string $value): void
    {
        $this->set($key, $value === null || $value === '' ? null : ['encrypted' => Crypt::encryptString($value)]);
    }

    public function getSecret(string $key): ?string
    {
        $value = $this->get($key);

        if (! is_array($value) || ! isset($value['encrypted'])) {
            return null;
        }

        try {
            return Crypt::decryptString((string) $value['encrypted']);
        } catch (DecryptException) {
            /*
             * The application key changed since the secret was saved. The
             * secret is gone; the only honest answer is "not configured".
             */
            return null;
        }
    }

    public function forget(string $key): void
    {
        $this->set($key, null);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $siteId = $this->siteContext->id();

        if ($siteId === null) {
            return $this->loaded = [];
        }

        try {
            $rows = Option::query()->where('site_id', $siteId)->get();
        } catch (QueryException) {
            return $this->loaded = [];
        }

        $loaded = [];

        foreach ($rows as $row) {
            if (is_array($row->value) && array_key_exists('value', $row->value)) {
                $loaded[$row->key] = $row->value['value'];
            }
        }

        return $this->loaded = $loaded;
    }

    public function flush(): void
    {
        $this->loaded = null;
    }
}
