<?php

namespace Gadya\Cms\Mail;

use Gadya\Connect\Portal\PortalClient;
use Illuminate\Support\Facades\Cache;

/**
 * What Gadya Media says about this site's email: the address it really
 * sends from, and what has gone out lately.
 *
 * The site can guess its own address from its name, but the portal is the
 * one that decides it - two clients called the same thing cannot share a
 * mailbox - so the panel asks rather than assumes. Asked here, on a screen
 * someone opened, and never while a message is on its way.
 */
class PortalMail
{
    public const CACHE_KEY = 'gadya-cms.mail.portal-status';

    /** Long enough that opening the screen twice is one call, short enough to be current. */
    private const CACHE_MINUTES = 10;

    public function __construct(
        private readonly PortalClient $portal,
        private readonly SharedSender $sender,
    ) {}

    /**
     * @return array{address: string, enabled: bool, sent_this_hour: int, per_hour: int, recent: list<array<string, mixed>>}|null
     */
    public function status(bool $fresh = false): ?array
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        $status = Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(self::CACHE_MINUTES),
            fn (): ?array => $this->fetch(),
        );

        return is_array($status) ? $status : null;
    }

    /**
     * The address the panel may promise, from the cache alone. Sending must
     * never wait on the portal to answer a second question, so this falls
     * back to the site's own guess rather than fetching.
     */
    public function address(): ?string
    {
        $cached = Cache::get(self::CACHE_KEY);

        return is_array($cached) && is_string($cached['address'] ?? null) ? $cached['address'] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetch(): ?array
    {
        $connection = $this->sender->connection();

        if ($connection === null) {
            return null;
        }

        return rescue(function () use ($connection): ?array {
            $response = $this->portal->send($connection, 'GET', PortalTransport::PATH);

            return $response->successful() ? (array) $response->json() : null;
        }, null, report: false);
    }
}
