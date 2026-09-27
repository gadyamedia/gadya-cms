<?php

namespace Gadya\Cms\Portal\Commands;

use Gadya\Cms\Support\Maintenance;

/**
 * `coming_soon.off`: open the site to everyone again.
 */
class ComingSoonOff
{
    public function __construct(private readonly Maintenance $maintenance) {}

    public function type(): string
    {
        return 'coming_soon.off';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{output: string, result: array<string, mixed>}
     */
    public function handle(array $payload): array
    {
        $was = $this->maintenance->isOn();

        $this->maintenance->save(['enabled' => false]);

        return [
            'output' => $was ? 'Coming soon mode is off: the site is open to everyone.' : 'The site was already open to everyone.',
            'result' => ['enabled' => false],
        ];
    }
}
