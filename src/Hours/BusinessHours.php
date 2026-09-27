<?php

namespace Gadya\Cms\Hours;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Editor\EditContext;

/**
 * The site's opening hours, read from the site document: the draft for
 * someone editing or previewing, the published hours for everyone else.
 */
class BusinessHours
{
    /** The top-level key of the site document the hours live under. */
    public const KEY = 'hours';

    public function __construct(
        private readonly SiteContentRepository $repository,
        private readonly EditContext $editor,
    ) {}

    public function current(): OpeningHours
    {
        $this->editor->boot();

        return $this->from($this->repository->forRequest());
    }

    public function published(): OpeningHours
    {
        return $this->from($this->repository->published());
    }

    public function draft(): OpeningHours
    {
        return $this->from($this->repository->draft());
    }

    /**
     * The `openingHoursSpecification` for the business's JSON-LD, or an
     * empty list when no hours are set.
     *
     * @return list<array<string, mixed>>
     */
    public function specification(): array
    {
        $hours = rescue(fn (): OpeningHours => $this->current(), null, report: false);

        return $hours?->isSet() ? $hours->specification(days: (int) config('gadya-cms.hours.upcoming_days', 60)) : [];
    }

    /**
     * What the portal is told at check-in, or null when no hours are set.
     *
     * @return array<string, mixed>|null
     */
    public function summary(): ?array
    {
        $hours = $this->published();

        if (! $hours->isSet()) {
            return null;
        }

        return [
            'timezone' => $hours->timezone(),
            'regular' => $hours->regular(),
            'exceptions' => $hours->upcomingExceptions(),
            'open_now' => $hours->isOpenAt(),
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function from(array $document): OpeningHours
    {
        $hours = $document[self::KEY] ?? null;

        return OpeningHours::fromArray(is_array($hours) ? $hours : null);
    }
}
