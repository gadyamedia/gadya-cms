<?php

namespace Gadya\Cms\Editor;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;

/**
 * An advisory lock that stops two editors clobbering each other's draft
 * edits at the same time. It is NOT a security control: it does not gate
 * access to any action, it only informs the UI who is currently editing so
 * Publish can be disabled for everyone except the current holder. The lock
 * always expires so a client who closes her laptop mid-edit can never lock
 * the site out permanently.
 */
class EditingLock
{
    private const KEY = 'gadya-cms.editing-lock';

    private function ttlMinutes(): int
    {
        return (int) config('gadya-cms.editor.lock_ttl_minutes', 15);
    }

    /**
     * Acquire the lock for the given user, or refresh it if she already
     * holds it. Returns false when another user currently holds the lock.
     */
    public function acquire(Authenticatable $user): bool
    {
        $current = Cache::get(self::KEY);

        if ($current !== null && $current['id'] !== $user->getAuthIdentifier()) {
            return false;
        }

        Cache::put(self::KEY, ['id' => $user->getAuthIdentifier(), 'name' => $user->name], now()->addMinutes($this->ttlMinutes()));

        return true;
    }

    /**
     * The display name of whoever currently holds the lock, or null when
     * the lock is free (either never taken, released, or expired).
     */
    public function holder(): ?string
    {
        return Cache::get(self::KEY)['name'] ?? null;
    }

    /**
     * Whether the given user is the current holder of the lock. Performs
     * no writes, so it is safe to call during a view render.
     */
    public function isHeldBy(Authenticatable $user): bool
    {
        $current = Cache::get(self::KEY);

        return $current !== null && $current['id'] === $user->getAuthIdentifier();
    }

    /**
     * Release the lock, but only if the given user is the one holding it.
     * This keeps the common cases (logout, exiting edit mode) tidy without
     * letting an editor who never held the lock clear someone else's.
     */
    public function release(Authenticatable $user): void
    {
        if ($this->isHeldBy($user)) {
            Cache::forget(self::KEY);
        }
    }
}
