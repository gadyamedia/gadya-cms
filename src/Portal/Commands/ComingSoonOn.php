<?php

namespace Gadya\Cms\Portal\Commands;

use Gadya\Cms\Support\Maintenance;
use Gadya\Cms\Support\SiteTimezone;
use Illuminate\Support\Carbon;

/**
 * `coming_soon.on`: close the site to visitors from the portal, the same
 * as ticking the box under Settings → Coming soon mode. Signed-in people
 * still see the site, so the client is never locked out of her own.
 */
class ComingSoonOn
{
    public function __construct(private readonly Maintenance $maintenance) {}

    public function type(): string
    {
        return 'coming_soon.on';
    }

    /**
     * @param  array<string, mixed>  $payload  Optional heading, message, until (ISO date) and password.
     * @return array{output: string, result: array<string, mixed>}
     */
    public function handle(array $payload): array
    {
        $changes = ['enabled' => true];

        foreach (['heading' => 120, 'message' => 500, 'password' => 60] as $key => $limit) {
            if (is_string($payload[$key] ?? null) && trim($payload[$key]) !== '') {
                $changes[$key] = mb_substr(trim($payload[$key]), 0, $limit);
            }
        }

        if (is_string($payload['until'] ?? null) && $payload['until'] !== '') {
            $until = rescue(fn (): Carbon => Carbon::parse($payload['until']), null, report: false);
            $changes['until'] = $until?->toDateTimeString();
        }

        $this->maintenance->save($changes);

        $until = $this->maintenance->until();

        return [
            'output' => 'Coming soon mode is on: visitors see "'.$this->maintenance->heading().'"'
                .($until === null ? '' : ' until '.app(SiteTimezone::class)->format($until, 'j F Y, g:ia'))
                .'. Anyone signed in still sees the site.',
            'result' => [
                'enabled' => $this->maintenance->isOn(),
                'heading' => $this->maintenance->heading(),
                'until' => $until?->toIso8601String(),
                'has_password' => $this->maintenance->hasPassword(),
            ],
        ];
    }
}
