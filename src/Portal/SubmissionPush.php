<?php

namespace Gadya\Cms\Portal;

use Gadya\Cms\Jobs\PushFormSubmission;
use Gadya\Cms\Jobs\PushSubmissionStatus;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Connect\Models\Connection;
use Gadya\Connect\Portal\PortalClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Hands each enquiry to the Gadya Media portal as it arrives, and tells
 * the portal when someone here opens or answers it.
 *
 * The check-in only says how many enquiries are waiting, every few
 * minutes at best; the portal can only chase an unanswered one, or ring a
 * visitor back, if it has the enquiry itself. Everything here is best
 * effort: the visitor's submission is already saved before any of it
 * runs, and nothing the portal says can undo that.
 */
class SubmissionPush
{
    public const PATH = '/api/connect/v1/submissions';

    /** Enquiries older than this are not worth sending late. */
    public const SWEEP_DAYS = 7;

    /** How many fields of a submission the portal keeps. */
    private const MAX_FIELDS = 50;

    public function __construct(private readonly PortalClient $portal) {}

    /** On unless switched off, and only on a site paired with the portal. */
    public function enabled(): bool
    {
        return (bool) config('gadya-cms.portal.push_submissions', true) && Connection::current() !== null;
    }

    /**
     * Queue the push. Rescued, so a queue that cannot be reached - or a
     * sync queue whose job throws - never reaches the visitor.
     */
    public function queue(FormSubmission $submission): void
    {
        if (! $this->enabled()) {
            return;
        }

        rescue(fn () => PushFormSubmission::dispatch($submission), report: false);
    }

    public function queueStatus(FormSubmission $submission): void
    {
        if (! $this->enabled()) {
            return;
        }

        rescue(fn () => PushSubmissionStatus::dispatch($submission), report: false);
    }

    /**
     * Send one enquiry. True once the portal has it (or has refused it for
     * good); throws when it is worth trying again.
     */
    public function push(FormSubmission $submission): bool
    {
        $connection = Connection::current();

        if ($connection === null || ! config('gadya-cms.portal.push_submissions', true)) {
            return false;
        }

        $response = $this->portal->send($connection, 'POST', self::PATH, $this->payload($submission));

        return $this->settle($submission, $response);
    }

    /**
     * Opened and answered, as the portal's lead chaser needs them: an
     * enquiry someone here has dealt with should stop being chased there.
     */
    public function pushStatus(FormSubmission $submission): bool
    {
        $connection = Connection::current();

        if ($connection === null || ! config('gadya-cms.portal.push_submissions', true)) {
            return false;
        }

        if ($submission->pushed_at === null && ! $this->push($submission)) {
            return false;
        }

        $response = $this->portal->send($connection, 'POST', self::PATH.'/'.rawurlencode((string) $submission->getKey()).'/status', [
            'opened_at' => $submission->read_at?->toIso8601String(),
            'answered_at' => $submission->answered_at?->toIso8601String(),
        ]);

        if ($response->successful()) {
            return true;
        }

        if ($this->isPermanent($response)) {
            Log::warning('The Gadya portal refused an enquiry status update.', ['submission' => $submission->getKey(), 'status' => $response->status()]);

            return false;
        }

        throw new RuntimeException('The portal did not take the enquiry status (HTTP '.$response->status().').');
    }

    /**
     * The enquiry in the portal's shape. The well-known fields are lifted
     * out so the portal can show a name and a number without guessing;
     * everything the form kept travels in `fields` as well.
     *
     * @return array<string, mixed>
     */
    public function payload(FormSubmission $submission): array
    {
        $data = is_array($submission->data) ? $submission->data : [];
        $consent = $this->consent($submission);

        return [
            'external_id' => (string) $submission->getKey(),
            'form' => Str::limit((string) $submission->form, 60, ''),
            'name' => $this->first($data, ['name', 'full_name']) ?? $this->joined($data, ['first_name', 'last_name']),
            'email' => $this->first($data, ['email', 'email_address']),
            'phone' => $this->first($data, ['phone', 'telephone', 'tel', 'mobile', 'phone_number']),
            'company' => $this->first($data, ['company', 'business', 'organisation', 'organization']),
            'message' => $this->first($data, ['message', 'enquiry', 'inquiry', 'comments', 'details']),
            'fields' => (object) collect($data)
                ->take(self::MAX_FIELDS)
                ->map(fn ($value): string|int|float|bool|null => is_array($value) ? implode(', ', array_map('strval', $value)) : $value)
                ->all(),
            'page_url' => $this->pageUrl($submission->path),
            'submitted_at' => ($submission->created_at ?? now())->toIso8601String(),
            'callback_requested' => $consent !== null,
            'callback_consent_text' => $consent['text'] ?? null,
            'callback_consented_at' => $consent['at'] ?? null,
            'callback_consent_ip' => $consent['ip'] ?? null,
        ];
    }

    /**
     * The unpushed enquiries still worth sending, oldest first.
     *
     * @return Builder<FormSubmission>
     */
    public function unpushed(): Builder
    {
        return FormSubmission::query()
            ->whereNull('pushed_at')
            ->where('created_at', '>=', now()->subDays(self::SWEEP_DAYS))
            ->orderBy('id');
    }

    /**
     * A callback's record of consent, when the visitor gave one.
     *
     * @return array{text: string, at: string|null, ip: string|null}|null
     */
    private function consent(FormSubmission $submission): ?array
    {
        $consent = $submission->getAttribute('consent');

        if (! is_array($consent) || blank($consent['text'] ?? null)) {
            return null;
        }

        return [
            'text' => (string) $consent['text'],
            'at' => isset($consent['at']) ? (string) $consent['at'] : $submission->created_at?->toIso8601String(),
            'ip' => isset($consent['ip']) ? (string) $consent['ip'] : null,
        ];
    }

    private function settle(FormSubmission $submission, Response $response): bool
    {
        if ($response->successful()) {
            $submission->forceFill(['pushed_at' => now()])->saveQuietly();

            return true;
        }

        /*
         * A refusal that will not change on a retry - a field the portal
         * will never accept - is written off rather than sent every five
         * minutes for a week. It is still in the inbox here.
         */
        if ($this->isPermanent($response)) {
            Log::warning('The Gadya portal refused an enquiry.', ['submission' => $submission->getKey(), 'status' => $response->status(), 'message' => Str::limit((string) $response->json('message'), 300)]);

            $submission->forceFill(['pushed_at' => now()])->saveQuietly();

            return true;
        }

        throw new RuntimeException('The portal did not take the enquiry (HTTP '.$response->status().').');
    }

    private function isPermanent(Response $response): bool
    {
        return $response->clientError() && ! in_array($response->status(), [401, 403, 404, 408, 409, 419, 423, 429], true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private function first(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (is_scalar($data[$key] ?? null) && trim((string) $data[$key]) !== '') {
                return Str::limit(trim((string) $data[$key]), 5000, '');
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private function joined(array $data, array $keys): ?string
    {
        $parts = array_filter(array_map(fn (string $key): ?string => $this->first($data, [$key]), $keys));

        return $parts === [] ? null : implode(' ', $parts);
    }

    private function pageUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return Str::startsWith($path, ['http://', 'https://']) ? $path : url($path);
    }
}
