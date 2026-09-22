<?php

namespace Gadya\Cms\Quality;

use Gadya\Cms\Mail\SharedSender;
use Gadya\Connect\Portal\PortalClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Whether the AI assistants people now ask for recommendations actually
 * name this business.
 *
 * The asking is done by the portal, weekly, because it holds the key and
 * because one account asking on behalf of every site is both cheaper and
 * less likely to be rate-limited than each site asking for itself. The
 * site only reads the answer.
 */
class Visibility
{
    public const PATH = '/api/connect/v1/visibility';

    public function __construct(
        private readonly PortalClient $portal,
        private readonly SharedSender $sender,
    ) {}

    /**
     * @return array{checked_at: string|null, queries: list<array<string, mixed>>, named: int, asked: int}|null
     */
    public function latest(): ?array
    {
        $connection = $this->sender->connection();

        if ($connection === null) {
            return null;
        }

        return Cache::remember('gadya-cms.visibility', now()->addHours(6), function () use ($connection): ?array {
            try {
                $response = $this->portal->send($connection, 'GET', self::PATH);
            } catch (Throwable) {
                return null;
            }

            if (! $response->successful()) {
                return null;
            }

            return [
                'checked_at' => $response->json('checked_at'),
                'queries' => (array) $response->json('queries', []),
                'named' => (int) $response->json('named', 0),
                'asked' => (int) $response->json('asked', 0),
            ];
        });
    }
}
