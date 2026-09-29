<?php

namespace Gadya\Cms\Models;

use Gadya\Cms\Database\Factories\FormFactory;
use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Forms\Builder\FormSchema;
use Gadya\Cms\Forms\FormLocale;
use Gadya\Cms\Localisation\Locales;
use Gadya\Cms\Localisation\Translations;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A form the client built in the panel.
 *
 * Its questions (`fields`), the words it says (`messages`) and where each
 * enquiry goes (`settings`) are one row. It is live the moment it is
 * published, like an article: a form is not part of the site document,
 * so there is no page-wide "Publish changes" to wait for. Every save of
 * a live form is kept as a version, and each enquiry notes the version it
 * answered.
 */
class Form extends Model
{
    /** @use HasFactory<FormFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    protected $table = 'gadyacms_forms';

    /** @var list<string> */
    protected $fillable = [
        'site_id', 'slug', 'title', 'description', 'status', 'fields', 'messages', 'settings',
        'version', 'template', 'published_at', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'messages' => 'array',
            'settings' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /**
     * The words a form says, as the package writes them until the client
     * writes her own.
     *
     * @return array<string, string>
     */
    public static function defaultMessages(): array
    {
        return [
            'success' => 'Thank you. We will be in touch soon.',
            'submit' => 'Send',
            'next' => 'Next',
            'back' => 'Back',
            'first_step' => '',
            'save_later' => 'Finish later',
            'save_later_intro' => 'We will email you a link to come back and finish. It works for a week.',
            'saved' => 'We have emailed you a link to finish later.',
            'restored' => 'We kept what you typed last time.',
            'start_again' => 'Start again',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultSettings(): array
    {
        return [
            'notify' => [],
            'notify_sms' => [],
            'routes' => [],
            'email_subject' => 'New {form} enquiry from {name}',
            'email_body' => '',
            'autoreply' => ['enabled' => false, 'subject' => 'Thank you for getting in touch with {business}', 'body' => "Hello {name},\n\nThank you for your message. We have it, and someone will come back to you shortly.\n\nBest wishes,\n{business}"],
            'webhooks' => [],
            'redirect' => null,
            'public_page' => true,
            'noindex' => true,
            'callback' => false,
            'save_later' => false,
            'local_progress' => true,
            'turnstile' => false,
            'css_class' => '',
            'analytics_event' => 'lead_form_submit',
            'progress' => true,
            'styles' => true,
            'destinations' => [],
            'destination_maps' => [],
            'localised' => [],
        ];
    }

    protected static function newFactory(): FormFactory
    {
        return FormFactory::new();
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<FormVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(FormVersion::class);
    }

    /** @return HasMany<FormSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    /** @return HasMany<FormEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(FormEvent::class);
    }

    /** @return HasMany<FormWebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(FormWebhookDelivery::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForCurrentSite(Builder $query): Builder
    {
        return $query->where('site_id', app(SiteContext::class)->id());
    }

    /**
     * A published form on this site, by its address. Anything else - a
     * draft, an archived form, a slug nobody built - is not a form a
     * visitor can see.
     */
    public static function findLive(string $slug): ?self
    {
        $form = rescue(
            fn (): ?self => static::query()->forCurrentSite()->live()->where('slug', $slug)->first(),
            null,
            report: false,
        );

        return $form?->inVisitorLanguage();
    }

    /**
     * Put the form into the visitor's language when the app chose it
     * rather than the CMS - a session, a cookie, a prefix of the app's
     * own. A request the CMS answers in another language has had it done
     * already, as the form was read.
     */
    public function inVisitorLanguage(): self
    {
        $locales = app(Locales::class);
        $locale = app(FormLocale::class)->current();

        if ($locales->isTranslating() || $locale === $locales->default()) {
            return $this;
        }

        app(Translations::class)->translateModel($this, $locale, app(EditContext::class)->showsDraft());

        return $this;
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function schema(): FormSchema
    {
        return new FormSchema((array) ($this->fields ?? []));
    }

    public function message(string $key): string
    {
        $written = trim((string) (($this->messages ?? [])[$key] ?? ''));

        return $written !== '' ? $written : (string) (self::defaultMessages()[$key] ?? '');
    }

    /**
     * The thank-you in a language: the words the client wrote for that
     * language under **After sending**, else the translation, else her
     * own words.
     */
    public function successMessage(?string $locale = null): string
    {
        $written = trim((string) ($this->localised($locale)['success'] ?? ''));

        return $written !== '' ? $written : $this->message('success');
    }

    /**
     * Where to take someone once they have sent it, in a language: that
     * language's own page, else the form's. Only ever a page on this site.
     */
    public function redirectFor(?string $locale = null): ?string
    {
        foreach ([$this->localised($locale)['redirect'] ?? null, $this->setting('redirect')] as $to) {
            if (is_string($to) && str_starts_with($to, '/') && ! str_starts_with($to, '//')) {
                return $to;
            }
        }

        return null;
    }

    /**
     * @return array{locale?: string, success?: string|null, redirect?: string|null}
     */
    private function localised(?string $locale): array
    {
        $locale = FormLocale::normalise($locale ?? app(FormLocale::class)->current());

        foreach ((array) $this->setting('localised', []) as $entry) {
            if (is_array($entry) && FormLocale::normalise($entry['locale'] ?? null) === $locale) {
                return $entry;
            }
        }

        return [];
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        $settings = [...self::defaultSettings(), ...array_filter((array) ($this->settings ?? []), fn ($value): bool => $value !== null)];

        return data_get($settings, $key, $default);
    }

    /** The shareable page for the form, when it has one. */
    public function publicUrl(): ?string
    {
        return $this->setting('public_page', true) ? route('gadya-cms.forms.page', $this->slug) : null;
    }

    public function embedUrl(): string
    {
        return route('gadya-cms.forms.embed', $this->slug);
    }

    /**
     * Keep the shape it has now as a numbered version, so an enquiry sent
     * against it can be read against the questions it was asked.
     */
    public function recordVersion(?int $userId = null): FormVersion
    {
        $latest = $this->versions()->max('version');

        if ($latest !== null && (int) $latest >= (int) $this->version) {
            $this->forceFill(['version' => (int) $latest + 1])->saveQuietly();
        }

        return $this->versions()->create([
            'version' => $this->version,
            'fields' => $this->fields,
            'messages' => $this->messages,
            'created_by' => $userId,
            'created_at' => now(),
        ]);
    }
}
