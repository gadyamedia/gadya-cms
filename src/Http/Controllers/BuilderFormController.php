<?php

namespace Gadya\Cms\Http\Controllers;

use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Analytics\VisitorFingerprint;
use Gadya\Cms\Content\PublicDocument;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Forms\Builder\FormFiles;
use Gadya\Cms\Forms\Builder\FormRenderer;
use Gadya\Cms\Forms\Builder\SpamGuard;
use Gadya\Cms\Forms\Builder\SubmitBuilderForm;
use Gadya\Cms\Forms\FormLocale;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\FormDraft;
use Gadya\Cms\Models\FormEvent;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Notifications\FormResumeLink;
use Gadya\Cms\Privacy\Consent;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Everything a visitor does with a built form: send it, check a step,
 * keep it for later and come back to it, see it on its own page or inside
 * another site, and be counted doing so. The admin's signed file links
 * are here too, because they are the one way to open an upload.
 */
class BuilderFormController extends Controller
{
    public function __construct(
        private readonly SubmitBuilderForm $submit,
        private readonly SpamGuard $spam,
    ) {}

    /**
     * Sent from a page of the site: answered with a redirect, or JSON for
     * the script.
     */
    public function store(Request $request, Form $form): JsonResponse|RedirectResponse
    {
        if ($request->boolean('_save')) {
            return $this->save($request, $form->slug);
        }

        if ($this->isBot($request, $form)) {
            return $this->success($request, $form);
        }

        $step = $request->input('_validate_step');

        ['validator' => $validator, 'visible' => $visible] = $this->submit->validator($form, $request, is_numeric($step) ? (int) $step : null);

        if ($validator->fails()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Please check your answers.', 'errors' => $validator->errors()], 422);
            }

            return back()
                ->withErrors($validator, 'gadya-cms.'.$form->slug)
                ->withInput($this->keptInput($request, $form));
        }

        if (is_numeric($step)) {
            return response()->json(['ok' => true]);
        }

        $this->submit->store($form, $validator->validated(), $request, $visible);

        return $this->success($request, $form);
    }

    /** The form's own page, for a link in a text or an email. */
    public function page(string $slug, SiteContentRepository $repository, PublicDocument $public, EditContext $editor): Response
    {
        $form = Form::findLive($slug);

        abort_if($form === null || ! $form->setting('public_page', true), 404);

        $editor->boot();
        $layout = config('gadya-cms.forms.builder.layout');
        $page = [
            'title' => $form->title,
            'heading' => $form->title,
            'description' => (string) $form->description,
            'seo' => ['noindex' => (bool) $form->setting('noindex', true)],
        ];

        $response = response()->view(is_string($layout) && $layout !== '' ? 'gadya-cms::forms.page-in-layout' : 'gadya-cms::forms.page', [
            'form' => $form,
            'page' => $page,
            'site' => $public->from($repository->forRequest()),
            'layout' => $layout,
        ]);

        return $form->setting('noindex', true) ? $response->header('X-Robots-Tag', 'noindex, nofollow') : $response;
    }

    /**
     * The form alone, for an iframe on another site. It keeps no session -
     * the browser will not send this site's cookies from inside someone
     * else's page - so it posts back here and is answered with the page
     * itself, errors and all.
     */
    public function embed(string $slug): Response
    {
        $form = Form::findLive($slug);

        abort_if($form === null, 404);

        return $this->embedPage($form);
    }

    public function embedStore(Request $request, string $slug): JsonResponse|Response
    {
        app(FormLocale::class)->adopt($request);

        $form = Form::findLive($slug);

        abort_if($form === null, 404);

        $form = app(FormLocale::class)->adoptFor($request, $form);

        if ($this->isBot($request, $form)) {
            return $request->expectsJson()
                ? response()->json(['ok' => true, 'message' => $form->successMessage()])
                : $this->embedPage($form, success: $form->successMessage());
        }

        $step = $request->input('_validate_step');

        ['validator' => $validator, 'visible' => $visible] = $this->submit->validator($form, $request, is_numeric($step) ? (int) $step : null);

        if ($validator->fails()) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Please check your answers.', 'errors' => $validator->errors()], 422)
                : $this->embedPage($form, errors: $validator->errors(), old: $this->keptInput($request, $form), status: 422);
        }

        if (is_numeric($step)) {
            return response()->json(['ok' => true]);
        }

        $this->submit->store($form, $validator->validated(), $request, $visible);

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'message' => $form->successMessage()])
            : $this->embedPage($form, success: $form->successMessage());
    }

    /**
     * "Email me a link to finish later": what they have typed so far, kept
     * behind a token only the email holds.
     */
    public function save(Request $request, string $slug): JsonResponse|RedirectResponse
    {
        $form = Form::findLive($slug);

        abort_if($form === null || ! $form->setting('save_later'), 404);

        if ($this->isHoneypotted($request)) {
            return $this->saved($request, $form);
        }

        $data = $request->validate(['_resume_email' => ['required', 'email', 'max:255']], [
            '_resume_email.required' => 'Please give an email address to send the link to.',
            '_resume_email.email' => 'Please give an email address like name@example.com.',
        ]);

        $token = Str::random(48);
        $days = max(1, (int) config('gadya-cms.forms.builder.resume_days', 7));

        FormDraft::query()->create([
            'form_id' => $form->getKey(),
            'token_hash' => FormDraft::hashToken($token),
            'email' => Str::lower($data['_resume_email']),
            'data' => $this->keptInput($request, $form),
            'step' => max(0, (int) $request->input('_step', 0)),
            'path' => Str::limit((string) ($request->input('_path') ?: ''), 255, ''),
            'expires_at' => now()->addDays($days),
        ]);

        $url = URL::temporarySignedRoute('gadya-cms.forms.resume', now()->addDays($days), ['slug' => $form->slug, 'token' => $token]);

        rescue(fn () => Notification::route('mail', $data['_resume_email'])->notify(new FormResumeLink($form, $url, $days)), report: true);

        return $this->saved($request, $form);
    }

    /**
     * Back from the email: what they typed is put back, and they land on
     * the page the form was on.
     */
    public function resume(string $slug, string $token): RedirectResponse
    {
        $form = Form::findLive($slug);

        abort_if($form === null, 404);

        $draft = FormDraft::query()
            ->where('form_id', $form->getKey())
            ->where('token_hash', FormDraft::hashToken($token))
            ->where('expires_at', '>', now())
            ->first();

        abort_if($draft === null, 410, 'This link has expired or the form has already been sent.');

        session()->flash('gadya-cms.forms.resume.'.$form->slug, ['data' => $draft->data ?? [], 'step' => $draft->step, 'token' => $token]);

        $path = (string) $draft->path;
        $to = $path !== '' && str_starts_with($path, '/') && ! str_starts_with($path, '//')
            ? $path
            : ($form->setting('public_page', true) ? route('gadya-cms.forms.page', $form->slug, false) : '/');

        return redirect()->to($to.'#cms-form-'.$form->slug);
    }

    /**
     * A view, a start or a step, from the form's script. Someone who said
     * no to analytics in the privacy banner is not counted at all, as
     * their page views are not; nor is an editor, or a bot.
     */
    public function events(Request $request, string $slug, Consent $consent): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', Rule::in([FormEvent::VIEW, FormEvent::START, FormEvent::STEP])],
            'step' => ['nullable', 'integer', 'min:0', 'max:50'],
            'path' => ['nullable', 'string', 'max:255'],
            'referrer' => ['nullable', 'string', 'max:500'],
        ]);

        $form = config('gadya-cms.analytics.enabled', true) ? Form::findLive($slug) : null;

        if ($form !== null && ! VisitorFingerprint::isBot($request) && ! $consent->refuses(Consent::ANALYTICS, $request)) {
            rescue(fn () => FormEvent::query()->create([
                'site_id' => $form->site_id,
                'form_id' => $form->getKey(),
                'name' => $data['name'],
                'step' => $data['name'] === FormEvent::STEP ? ($data['step'] ?? null) : null,
                'visitor_hash' => VisitorFingerprint::hash($request),
                'path' => Str::limit(Str::start((string) parse_url((string) ($data['path'] ?? '/'), PHP_URL_PATH), '/'), 255, ''),
                'referrer_host' => $this->referrerHost($data['referrer'] ?? null, $request),
                'created_at' => now(),
            ]), report: false);
        }

        return response()->json(['recorded' => true]);
    }

    /**
     * One uploaded file, through a signed link from the admin, for someone
     * allowed to read enquiries.
     */
    public function file(Request $request, FormSubmission $submission, string $field, int $index, FormFiles $files): StreamedResponse
    {
        abort_unless($request->user()?->can(Abilities::gate(Abilities::ENQUIRIES)) ?? false, 403);
        abort_unless($submission->site_id === app(SiteContext::class)->id(), 404);

        $file = ($submission->files ?? [])[$field][$index] ?? null;

        abort_if(! is_array($file) || ! is_string($file['path'] ?? null) || ! $files->disk()->exists($file['path']), 404);

        return $files->disk()->download($file['path'], (string) ($file['name'] ?? basename($file['path'])), [
            'Content-Type' => (string) ($file['mime'] ?? 'application/octet-stream'),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function isBot(Request $request, Form $form): bool
    {
        return $this->isHoneypotted($request) || $this->spam->isTooFast($request, $form->slug);
    }

    private function isHoneypotted(Request $request): bool
    {
        $honeypot = (string) config('gadya-cms.forms.honeypot', 'website');

        return $honeypot !== '' && filled($request->input($honeypot));
    }

    /**
     * What is worth putting back in the form: never the honeypot, the
     * seal, a file or a signature.
     *
     * @return array<string, mixed>
     */
    private function keptInput(Request $request, Form $form): array
    {
        $skip = [(string) config('gadya-cms.forms.honeypot', 'website'), '_token', '_t', '_save', '_validate_step', 'cf-turnstile-response'];

        foreach ($form->schema()->inputs() as $field) {
            if (in_array($field['type'], ['file', 'image', 'signature'], true)) {
                $skip[] = $field['key'];
            }
        }

        return array_filter($request->except($skip), fn ($value): bool => ! is_object($value));
    }

    /**
     * Thanked in the language they filled it in: that language's
     * thank-you, flashed for the page they land on, and that language's
     * page to go to when the form has one.
     */
    private function success(Request $request, Form $form): JsonResponse|RedirectResponse
    {
        $redirect = $this->redirectTarget($request, $form);
        $message = $form->successMessage();

        if ($request->expectsJson()) {
            return response()->json(array_filter(['ok' => true, 'message' => $message, 'redirect' => $redirect]));
        }

        return ($redirect !== null ? redirect()->to($redirect) : back())->with('gadya-cms.form.'.$form->slug, $message);
    }

    private function saved(Request $request, Form $form): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['ok' => true, 'message' => $form->message('saved')])
            : back()->with('gadya-cms.form-saved.'.$form->slug, $form->message('saved'));
    }

    /** Only ever somewhere on this site. */
    private function redirectTarget(Request $request, Form $form): ?string
    {
        foreach ([$form->redirectFor(), $request->input('_redirect')] as $to) {
            if (is_string($to) && str_starts_with($to, '/') && ! str_starts_with($to, '//')) {
                return $to;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $old
     */
    private function embedPage(Form $form, ?MessageBag $errors = null, ?array $old = null, ?string $success = null, int $status = 200): Response
    {
        return response()->view('gadya-cms::forms.embed', [
            'form' => $form,
            'html' => app(FormRenderer::class)->render($form, array_filter([
                'embed' => true,
                'errors' => $errors ?? new MessageBag,
                'old' => $old ?? [],
                'success' => $success,
            ], fn ($value): bool => $value !== null)),
        ], $status)->header('Content-Security-Policy', 'frame-ancestors *');
    }

    private function referrerHost(?string $referrer, Request $request): ?string
    {
        $host = parse_url((string) $referrer, PHP_URL_HOST);

        return is_string($host) && $host !== '' && $host !== $request->getHost() ? Str::limit($host, 255, '') : null;
    }
}
