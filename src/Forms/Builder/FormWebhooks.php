<?php

namespace Gadya\Cms\Forms\Builder;

use Gadya\Cms\Jobs\DeliverFormWebhook;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Models\FormWebhookDelivery;
use Illuminate\Support\Str;

/**
 * Each enquiry, handed to the services the client connected - Zapier,
 * Make, a booking system - as JSON, signed when she gave a secret, tried
 * again when the other end does not answer, and logged either way.
 */
class FormWebhooks
{
    public const SIGNATURE_HEADER = 'X-Gadya-Signature';

    public const EVENT_HEADER = 'X-Gadya-Event';

    public function dispatch(Form $form, FormSubmission $submission): int
    {
        $sent = 0;

        foreach ((array) $form->setting('webhooks', []) as $hook) {
            if (! is_array($hook) || ! ($hook['active'] ?? true) || ! $this->isAllowedUrl((string) ($hook['url'] ?? ''))) {
                continue;
            }

            $delivery = FormWebhookDelivery::query()->create([
                'form_id' => $form->getKey(),
                'submission_id' => $submission->getKey(),
                'url' => Str::limit((string) $hook['url'], 500, ''),
            ]);

            rescue(fn () => DeliverFormWebhook::dispatch($delivery, filled($hook['secret'] ?? null) ? (string) $hook['secret'] : null), report: false);
            $sent++;
        }

        return $sent;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(FormSubmission $submission): array
    {
        return [
            'event' => 'form.submitted',
            'form' => ['slug' => $submission->form, 'title' => $submission->builderForm?->title, 'version' => $submission->form_version],
            'submission' => [
                'id' => $submission->getKey(),
                'submitted_at' => $submission->created_at?->toIso8601String(),
                'page' => $submission->path,
                'sender' => $submission->sender(),
            ],
            'data' => (object) ($submission->data ?? []),
            'labels' => (object) $submission->fieldLabels(),
            'site' => url('/'),
        ];
    }

    public static function sign(string $body, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    /**
     * An address on the internet, never this server's own network: a
     * webhook is typed in the panel, and must not become a way to poke at
     * things behind the firewall.
     */
    public function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($parts['scheme'] ?? null, ['https', 'http'], true) || $host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }

        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }
}
