<?php

namespace Gadya\Cms\Forms;

use Gadya\Cms\Analytics\VisitorFingerprint;
use Gadya\Cms\Analytics\VisitorGeo;
use Gadya\Cms\Models\AnalyticsEvent;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Notifications\FormSubmitted;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class StoreFormSubmission
{
    public function __construct(private readonly SiteContext $siteContext) {}

    /**
     * @param  array<string, mixed>  $data  Already validated against the form's rules.
     */
    public function handle(FormDefinition $form, array $data, Request $request): FormSubmission
    {
        $kept = array_intersect_key($data, array_flip($form->fields()));

        $submission = FormSubmission::query()->create([
            'site_id' => $this->siteContext->id(),
            'form' => $form->name,
            'data' => $kept,
            'path' => Str::limit((string) ($request->input('_path') ?: $request->headers->get('referer')), 255, ''),
            'referrer_host' => parse_url((string) $request->headers->get('referer'), PHP_URL_HOST) ?: null,
            'country' => VisitorGeo::for($request)['country'],
            'created_at' => now(),
        ]);

        if ($form->analyticsEvent !== null && config('gadya-cms.analytics.enabled', true)) {
            rescue(fn () => AnalyticsEvent::query()->create([
                'site_id' => $submission->site_id,
                'name' => $form->analyticsEvent,
                'path' => Str::limit(Str::start((string) parse_url((string) $submission->path, PHP_URL_PATH) ?: '/', '/'), 255, ''),
                'visitor_hash' => VisitorFingerprint::hash($request),
                'metadata' => ['form' => $form->name],
                'created_at' => now(),
            ]), report: false);
        }

        if ($form->notify !== []) {
            rescue(fn () => Notification::route('mail', $form->notify)->notify(new FormSubmitted($submission, $form)), report: true);
        }

        return $submission;
    }
}
