<?php

namespace Gadya\Cms\Jobs;

use Gadya\Cms\Sms\TextAlerts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One enquiry alert to one mobile. Tried again when Twilio is down or
 * answers with a 5xx; never when it refuses the text outright (a wrong
 * number, an unverified sender, a number that replied STOP), because the
 * same text would be refused again.
 */
class SendTextAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly string $number,
        public readonly string $message,
        public readonly ?int $submissionId = null,
    ) {}

    public function handle(TextAlerts $alerts): void
    {
        if (! $alerts->enabled() || $alerts->isOptedOut($this->number)) {
            return;
        }

        $alerts->send($this->number, $this->message);
    }
}
