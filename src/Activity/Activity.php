<?php

namespace Gadya\Cms\Activity;

use Gadya\Cms\Models\AuditLog;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Who changed what, and when.
 *
 * Every question that starts "who deleted the…" ends here. Recording is
 * always rescued: a site must never fail to save a page because it could
 * not write a note about saving the page.
 */
class Activity
{
    public function __construct(private readonly SiteContext $siteContext) {}

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function record(string $event, ?string $subject = null, array $before = [], array $after = []): void
    {
        if (! config('gadya-cms.activity.enabled', true)) {
            return;
        }

        rescue(fn () => AuditLog::query()->create([
            'site_id' => $this->siteContext->id(),
            'user_id' => auth()->id(),
            'event' => Str::limit($event, 120, ''),
            'subject' => $subject === null ? null : Str::limit($subject, 250, ''),
            'before' => $before === [] ? null : $before,
            'after' => $after === [] ? null : $after,
            'ip_address' => null,
        ]), report: false);
    }

    /**
     * A model's change, in the words the panel uses for it.
     */
    public function recordModel(string $verb, Model $model): void
    {
        $this->record(
            $this->nameFor($model).'.'.$verb,
            $this->subjectFor($model),
            [],
            $verb === 'updated' ? $this->changesOf($model) : [],
        );
    }

    public function nameFor(Model $model): string
    {
        return Str::lower(Str::snake(class_basename($model)));
    }

    public function subjectFor(Model $model): string
    {
        foreach (['title', 'name', 'label', 'email', 'from_path', 'slug'] as $attribute) {
            if (filled($model->{$attribute} ?? null)) {
                return (string) $model->{$attribute};
            }
        }

        return '#'.$model->getKey();
    }

    /**
     * What actually changed, without the noise: timestamps, and the draft
     * and published blobs, whose diff nobody could read anyway.
     *
     * @return array<string, mixed>
     */
    private function changesOf(Model $model): array
    {
        $ignored = ['updated_at', 'created_at', 'draft', 'published', 'snapshot', 'ai_meta', 'remember_token', 'password'];

        return collect($model->getChanges())
            ->except($ignored)
            ->map(fn ($value): mixed => is_scalar($value) || $value === null ? $value : '…')
            ->all();
    }

    /**
     * How an event reads in the panel.
     */
    public static function describe(string $event): string
    {
        [$thing, $verb] = array_pad(explode('.', $event, 2), 2, '');

        return match ($event) {
            'site.published' => 'Published the site',
            'site.publish_scheduled' => 'Scheduled a publish',
            'site.publish_cancelled' => 'Cancelled a scheduled publish',
            'site.reverted' => 'Restored an earlier version',
            'site.maintenance_on' => 'Turned on coming-soon mode',
            'site.maintenance_off' => 'Turned off coming-soon mode',
            default => trim(Str::headline($verb).' '.Str::lower(Str::headline($thing))),
        };
    }
}
