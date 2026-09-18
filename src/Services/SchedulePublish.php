<?php

namespace Gadya\Cms\Services;

use Gadya\Cms\Activity\Activity;
use Gadya\Cms\Options\Options;
use Illuminate\Support\Carbon;

/**
 * "Make all of this go live on Friday at nine."
 *
 * Pages can already be given their own dates; this is the other thing a
 * client means - the whole draft, as it stands, at one moment. Kept as an
 * option rather than in the document, because a pending publish is not
 * content and must not travel in a revision.
 */
class SchedulePublish
{
    public function __construct(
        private readonly Options $options,
        private readonly PublishSiteContent $publish,
        private readonly Activity $activity,
    ) {}

    public function at(): ?Carbon
    {
        $at = $this->options->get('publish.at');

        return is_string($at) && $at !== '' ? rescue(fn (): Carbon => Carbon::parse($at), null, report: false) : null;
    }

    public function label(): ?string
    {
        $label = $this->options->get('publish.label');

        return is_string($label) && $label !== '' ? $label : null;
    }

    public function isPending(): bool
    {
        return $this->at() !== null;
    }

    public function schedule(Carbon $at, ?string $label = null): void
    {
        $this->options->set('publish.at', $at->toIso8601String());
        $this->options->set('publish.label', $label);

        $this->activity->record('site.publish_scheduled', $at->format('D j M Y, g:ia'));
    }

    public function cancel(): void
    {
        $this->options->forget('publish.at');
        $this->options->forget('publish.label');

        $this->activity->record('site.publish_cancelled');
    }

    /**
     * Publish if the moment has come. Answers whether it did, so the
     * command can say so.
     */
    public function publishIfDue(): bool
    {
        $at = $this->at();

        if ($at === null || $at->isFuture()) {
            return false;
        }

        $this->publish->handle(null, $this->label() ?? 'Scheduled publish');
        $this->cancel();

        return true;
    }
}
