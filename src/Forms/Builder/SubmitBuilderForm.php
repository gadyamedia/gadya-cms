<?php

namespace Gadya\Cms\Forms\Builder;

use Gadya\Cms\Analytics\VisitorFingerprint;
use Gadya\Cms\Analytics\VisitorGeo;
use Gadya\Cms\Localisation\Locales;
use Gadya\Cms\Models\AnalyticsEvent;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\FormDraft;
use Gadya\Cms\Models\FormEvent;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Models\Subscriber;
use Gadya\Cms\Portal\SubmissionPush;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Checks and keeps what a visitor sent through a built form.
 *
 * The rules come from the form as it stands, and only for the questions
 * that were showing given the answers: a hidden question is never
 * required and its answer never kept, whatever the browser sent. What is
 * kept goes into the same inbox as every other enquiry - `form` is the
 * form's slug, so a built form that replaced a configured one keeps its
 * enquiries, its emails and its analytics together.
 */
class SubmitBuilderForm
{
    public function __construct(
        private readonly FormLogic $logic,
        private readonly FormFiles $files,
        private readonly SpamGuard $spam,
        private readonly SiteContext $siteContext,
    ) {}

    /**
     * The rules for what was posted, and the questions that were showing.
     *
     * @param  array<string, mixed>  $input
     * @return array{rules: array<string, list<mixed>>, attributes: array<string, string>, visible: list<string>}
     */
    public function rules(Form $form, array $input, ?int $onlyStep = null): array
    {
        $schema = $form->schema();
        $visible = $this->logic->visible($schema, $input);
        $stepOf = $schema->stepOf();
        $rules = [];
        $attributes = [];

        foreach ($schema->inputs() as $field) {
            $key = $field['key'];
            $type = $schema->type($field);

            if ($type === null || ! in_array($key, $visible, true) || ($onlyStep !== null && ($stepOf[$key] ?? 0) !== $onlyStep)) {
                continue;
            }

            $label = Str::limit($field['label'] !== '' ? $field['label'] : Str::headline($key), 60);

            foreach ($type->rulesFor($field) as $part => $partRules) {
                $path = $part === '' ? $key : $key.'.'.$part;
                $accepts = in_array('accepted', $partRules, true);

                $prefix = match (true) {
                    $part === '*', $accepts => [],
                    $field['required'] && $part !== 'line2' => ['required'],
                    default => ['nullable'],
                };

                $rules[$path] = [...$prefix, ...$partRules];
                $attributes[$path] = in_array($part, ['', '*'], true) ? $label : $label.' ('.$this->partLabel($part).')';
            }
        }

        return ['rules' => $rules, 'attributes' => $attributes, 'visible' => $visible];
    }

    /**
     * @return array{validator: ValidatorContract, visible: list<string>}
     */
    public function validator(Form $form, Request $request, ?int $onlyStep = null): array
    {
        $input = $request->all();
        ['rules' => $rules, 'attributes' => $attributes, 'visible' => $visible] = $this->rules($form, $input, $onlyStep);

        $validator = Validator::make($input, $rules, [
            'required' => 'Please answer ":attribute".',
            'accepted' => 'Please tick ":attribute" to go on.',
            'email' => 'Please give an email address like name@example.com for ":attribute".',
            'regex' => 'Please check ":attribute" - it does not look right.',
            'extensions' => '":attribute" must be one of these kinds of file: :values.',
            'in' => 'Please choose one of the answers for ":attribute".',
        ], $attributes);

        /*
         * Turnstile is checked with the whole form, never a single step:
         * each token can only be spent once.
         */
        if ($onlyStep === null && $form->setting('turnstile') && $this->spam->turnstileConfigured()) {
            $validator->after(function ($validator) use ($request): void {
                if (! $this->spam->passesTurnstile($request)) {
                    $validator->errors()->add('cf-turnstile-response', 'Please confirm you are a person, then send the form again.');
                }
            });
        }

        return ['validator' => $validator, 'visible' => $visible];
    }

    /**
     * Keep what was sent.
     *
     * @param  array<string, mixed>  $validated
     * @param  list<string>  $visible
     */
    public function store(Form $form, array $validated, Request $request, array $visible): FormSubmission
    {
        /*
         * Answers are kept in the site's own language, whatever language
         * the form was filled in: the choices' words come from the form
         * as the client wrote it. The consent wording is the exception -
         * it is kept exactly as the visitor read it.
         */
        $original = app(Locales::class)->inDefault(fn (): ?Form => Form::query()->find($form->getKey())) ?? $form;
        $schema = $original->schema();
        $shown = $form->schema();
        $data = [];
        $files = [];
        $consents = [];
        $callback = null;

        foreach ($schema->inputs() as $field) {
            $key = $field['key'];
            $type = $schema->type($field);

            if ($type === null || ! in_array($key, $visible, true)) {
                continue;
            }

            $raw = $validated[$key] ?? null;

            if ($type->isFile()) {
                $stored = $field['type'] === 'signature' ? $this->signature($raw, $original->slug) : $this->uploads($request, $key, $original->slug);

                if ($stored === null) {
                    continue;
                }

                if ($stored['files'] !== []) {
                    $files[$key] = $stored['files'];
                }

                $data[$key] = $stored['text'];

                continue;
            }

            $boolean = in_array($field['type'], ['checkbox', 'consent', 'mailing_list'], true);

            if (! $boolean && $this->isBlank($raw)) {
                continue;
            }

            $value = $type->normalise($raw, $field);

            if (! $boolean && $this->isBlank($value)) {
                continue;
            }

            $data[$key] = $value;

            if ($field['type'] === 'consent' && $value === 'Yes') {
                $seen = $shown->field($key) ?? $field;
                $record = [
                    'text' => Str::limit(trim((string) (filled($seen['text'] ?? null) ? $seen['text'] : $seen['label'])), 1000, ''),
                    'at' => now()->toIso8601String(),
                    'ip' => $request->ip(),
                ];

                $consents[$key] = $record;

                if ($original->setting('callback') && ($callback === null || ($field['rules']['callback'] ?? false))) {
                    $callback = $record;
                }
            }
        }

        $duration = $this->duration($request, $original->slug);

        $submission = FormSubmission::query()->create([
            'site_id' => $this->siteContext->id(),
            'form' => $original->slug,
            'form_id' => $original->getKey(),
            'form_version' => $original->version,
            'data' => $data,
            'files' => $files === [] ? null : $files,
            'meta' => array_filter([
                'types' => array_intersect_key($schema->types(), $data),
                'labels' => array_intersect_key($schema->labels(), $data),
                'consents' => $consents,
                'locale' => app(Locales::class)->current(),
                'duration_seconds' => $duration,
            ], fn ($value): bool => $value !== [] && $value !== null),
            'path' => Str::limit((string) ($request->input('_path') ?: $request->headers->get('referer')), 255, ''),
            'referrer_host' => parse_url((string) $request->headers->get('referer'), PHP_URL_HOST) ?: null,
            'country' => VisitorGeo::for($request)['country'],
            'consent' => $callback,
            'created_at' => now(),
        ]);

        $this->joinMailingList($original, $submission, $schema);
        $this->count($original, $submission, $request, $duration);
        $this->forgetDraft($original, $request);
        $this->tell($original, $submission);

        return $submission;
    }

    /**
     * Everyone who should hear about it: the client, the portal, anything
     * listening on a webhook. None of it can reach the visitor.
     */
    protected function tell(Form $form, FormSubmission $submission): void
    {
        rescue(fn () => app(FormNotifier::class)->send($form, $submission), report: true);
        rescue(fn () => app(FormWebhooks::class)->dispatch($form, $submission), report: true);
        rescue(fn () => app(SubmissionPush::class)->queue($submission), report: false);
    }

    /**
     * @return array{text: string, files: list<array{path: string, name: string, size: int, mime: string}>}|null
     */
    private function uploads(Request $request, string $key, string $slug): ?array
    {
        $uploads = array_values(array_filter(
            (array) $request->file($key),
            fn ($file): bool => $file instanceof UploadedFile && $file->isValid(),
        ));

        if ($uploads === []) {
            return null;
        }

        $stored = array_map(fn (UploadedFile $file): array => $this->files->store($file, $slug), $uploads);

        return ['text' => implode(', ', array_column($stored, 'name')), 'files' => $stored];
    }

    /**
     * @return array{text: string, files: list<array{path: string, name: string, size: int, mime: string}>}|null
     */
    private function signature(mixed $raw, string $slug): ?array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        if (! str_starts_with($raw, 'data:')) {
            return ['text' => 'Signed as "'.trim($raw).'"', 'files' => []];
        }

        $stored = $this->files->storeSignature($raw, $slug);

        return $stored === null ? null : ['text' => 'Signed (the signature is attached)', 'files' => [$stored]];
    }

    /**
     * Ticked "keep me posted" with an email address: on the mailing list,
     * as if they had used the sign-up box.
     */
    private function joinMailingList(Form $form, FormSubmission $submission, FormSchema $schema): void
    {
        $optedIn = collect($schema->inputs())
            ->contains(fn (array $field): bool => $field['type'] === 'mailing_list' && ($submission->data[$field['key']] ?? null) === 'Yes');
        $email = $submission->answerOfType(['email']) ?? (is_string($submission->data['email'] ?? null) ? $submission->data['email'] : null);

        if (! $optedIn || $email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        rescue(function () use ($form, $submission, $email): void {
            $subscriber = Subscriber::query()->firstOrNew(['site_id' => $submission->site_id, 'email' => Str::lower($email)]);
            $name = $submission->answerOfType(['name']);

            $subscriber->fill([
                'name' => $name ?? $subscriber->name,
                'status' => Subscriber::SUBSCRIBED,
                'unsubscribed_at' => null,
                'source' => $subscriber->source ?? Str::limit('Form: '.$form->title, 255, ''),
            ])->save();
        }, report: false);
    }

    private function count(Form $form, FormSubmission $submission, Request $request, ?int $duration): void
    {
        if (! config('gadya-cms.analytics.enabled', true)) {
            return;
        }

        $path = Str::limit(Str::start((string) parse_url((string) $submission->path, PHP_URL_PATH) ?: '/', '/'), 255, '');
        $visitor = VisitorFingerprint::hash($request);
        $event = $form->setting('analytics_event');

        if (is_string($event) && $event !== '') {
            rescue(fn () => AnalyticsEvent::query()->create([
                'site_id' => $submission->site_id,
                'name' => $event,
                'path' => $path,
                'visitor_hash' => $visitor,
                'metadata' => ['form' => $form->slug],
                'created_at' => now(),
            ]), report: false);
        }

        rescue(fn () => FormEvent::query()->create([
            'site_id' => $submission->site_id,
            'form_id' => $form->getKey(),
            'name' => FormEvent::COMPLETE,
            'visitor_hash' => $visitor,
            'path' => $path,
            'referrer_host' => $submission->referrer_host,
            'duration_seconds' => $duration,
            'created_at' => now(),
        ]), report: false);
    }

    private function forgetDraft(Form $form, Request $request): void
    {
        $token = $request->input('_resume');

        if (is_string($token) && $token !== '') {
            rescue(fn () => FormDraft::query()->where('form_id', $form->getKey())->where('token_hash', FormDraft::hashToken($token))->delete(), report: false);
        }
    }

    /**
     * How long they took, from the first thing they typed where the
     * script noted it, or else from when the form was drawn.
     */
    private function duration(Request $request, string $slug): ?int
    {
        $now = now()->getTimestamp();
        $started = $request->input('_started');
        $from = is_numeric($started) ? (int) $started : $this->spam->drawnAt(is_string($request->input('_t')) ? $request->input('_t') : null, $slug);

        if ($from === null || $from > $now || $now - $from > 86400) {
            return null;
        }

        return $now - $from;
    }

    private function isBlank(mixed $value): bool
    {
        if (is_array($value)) {
            return array_filter($value, fn ($part): bool => ! $this->isBlank($part)) === [];
        }

        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function partLabel(string $part): string
    {
        return match ($part) {
            'first' => 'first name',
            'last' => 'last name',
            'line1' => 'street address',
            'line2' => 'apartment',
            'city' => 'city',
            'state' => 'state',
            'zip' => 'ZIP code',
            default => Str::lower(Str::headline($part)),
        };
    }
}
