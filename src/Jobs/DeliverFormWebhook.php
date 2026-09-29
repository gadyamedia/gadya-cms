<?php

namespace Gadya\Cms\Jobs;

use Gadya\Cms\Forms\Builder\FormWebhooks;
use Gadya\Cms\Models\FormWebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * One enquiry to one webhook. A 2xx is delivered; a refusal that will not
 * change (most 4xx) is marked failed at once; anything else - a timeout,
 * a 5xx, a 429 - is tried again, a few times over the next half hour.
 */
class DeliverFormWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        public readonly FormWebhookDelivery $delivery,
        public readonly ?string $secret = null,
    ) {
        $this->tries = max(1, (int) config('gadya-cms.forms.builder.webhooks.tries', 5));
    }

    public function handle(FormWebhooks $webhooks): void
    {
        $delivery = $this->delivery->fresh();
        $submission = $delivery?->submission;

        if ($delivery === null || $submission === null || $delivery->status === FormWebhookDelivery::SUCCEEDED) {
            return;
        }

        $body = (string) json_encode($webhooks->payload($submission), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = [FormWebhooks::EVENT_HEADER => 'form.submitted', 'User-Agent' => 'GadyaCMS-Webhooks/1'];

        if ($this->secret !== null) {
            $headers[FormWebhooks::SIGNATURE_HEADER] = FormWebhooks::sign($body, $this->secret);
        }

        $delivery->forceFill(['attempts' => $delivery->attempts + 1])->save();

        try {
            $response = Http::withHeaders($headers)
                ->timeout((int) config('gadya-cms.forms.builder.webhooks.timeout', 10))
                ->withBody($body, 'application/json')
                ->post($delivery->url);
        } catch (Throwable $exception) {
            $delivery->forceFill(['error' => Str::limit($exception->getMessage(), 500, '')])->save();

            throw new RuntimeException('The webhook could not be reached.', previous: $exception);
        }

        $delivery->forceFill([
            'response_status' => $response->status(),
            'response_body' => Str::limit($response->body(), 2000, ''),
        ]);

        if ($response->successful()) {
            $delivery->forceFill(['status' => FormWebhookDelivery::SUCCEEDED, 'delivered_at' => now(), 'error' => null])->save();

            return;
        }

        if ($response->clientError() && ! in_array($response->status(), [408, 409, 425, 429], true)) {
            $delivery->forceFill(['status' => FormWebhookDelivery::FAILED, 'error' => 'Refused (HTTP '.$response->status().')'])->save();

            return;
        }

        $delivery->forceFill(['error' => 'HTTP '.$response->status()])->save();

        throw new RuntimeException('The webhook answered HTTP '.$response->status().'.');
    }

    public function failed(?Throwable $exception): void
    {
        $this->delivery->fresh()?->forceFill(['status' => FormWebhookDelivery::FAILED])->save();
    }
}
