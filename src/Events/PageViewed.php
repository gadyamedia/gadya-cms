<?php

namespace Gadya\Cms\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A tick for the dashboard's live panel - never a tracking record.
 *
 * Broadcast now rather than queued: liveness is the entire point, and
 * routing it through a worker would make "who is on the site right now"
 * depend on one being awake.
 *
 * The payload deliberately carries no visitor identifier at all, not even
 * the daily hash the database holds: the page, the coarse place and the
 * device are everything the panel needs, and anything more would put a
 * thread between a person and their browsing onto a socket.
 */
class PageViewed implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly string $path,
        public readonly ?string $country,
        public readonly ?string $city,
        public readonly string $device,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(static::channel());
    }

    public function broadcastAs(): string
    {
        return 'page.viewed';
    }

    /**
     * @return array{path: string, country: ?string, city: ?string, device: string}
     */
    public function broadcastWith(): array
    {
        return [
            'path' => $this->path,
            'country' => $this->country,
            'city' => $this->city,
            'device' => $this->device,
        ];
    }

    public static function channel(): string
    {
        return 'gadya-cms.analytics';
    }
}
