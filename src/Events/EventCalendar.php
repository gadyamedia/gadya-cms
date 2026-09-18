<?php

namespace Gadya\Cms\Events;

use Gadya\Cms\Models\Event;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * What is on, and the calendar file for it.
 *
 * The iCal feed is written by hand: the format is a dozen lines, and a
 * dependency that folds lines at 75 octets is not worth taking on for it.
 */
class EventCalendar
{
    public function __construct(private readonly SiteContext $siteContext) {}

    /**
     * @return Collection<int, Event>
     */
    public function upcoming(int $limit = 50): Collection
    {
        return Event::query()->where('site_id', $this->siteContext->id())->upcoming()->limit($limit)->get();
    }

    /**
     * @return Collection<int, Event>
     */
    public function past(int $limit = 20): Collection
    {
        return Event::query()->where('site_id', $this->siteContext->id())->past()->limit($limit)->get();
    }

    public function find(string $slug): ?Event
    {
        return Event::query()->where('site_id', $this->siteContext->id())->where('slug', $slug)->first();
    }

    public function findLive(string $slug): ?Event
    {
        return Event::query()->where('site_id', $this->siteContext->id())->published()->where('slug', $slug)->first();
    }

    /**
     * An iCalendar feed of everything still to come, which a phone can
     * subscribe to and keep up to date.
     */
    public function ics(): string
    {
        $site = (string) config('gadya-cms.seo.site_name', config('gadya-cms.brand.name', config('app.name')));
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Gadya CMS//'.$this->escape($site).'//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escape($site),
        ];

        foreach ($this->upcoming(200) as $event) {
            $start = $event->starts_at;
            $end = $event->ends_at ?? $start->copy()->addHour();

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:event-'.$event->getKey().'@'.$host;
            $lines[] = 'DTSTAMP:'.$event->updated_at?->utc()->format('Ymd\THis\Z');

            if ($event->all_day) {
                $lines[] = 'DTSTART;VALUE=DATE:'.$start->format('Ymd');
                $lines[] = 'DTEND;VALUE=DATE:'.$end->copy()->addDay()->format('Ymd');
            } else {
                $lines[] = 'DTSTART:'.$start->utc()->format('Ymd\THis\Z');
                $lines[] = 'DTEND:'.$end->utc()->format('Ymd\THis\Z');
            }

            $lines[] = 'SUMMARY:'.$this->escape($event->title);
            $lines[] = 'URL:'.url($event->publicPath());

            if (filled($event->summary)) {
                $lines[] = 'DESCRIPTION:'.$this->escape((string) $event->summary);
            }

            if (filled($event->location)) {
                $lines[] = 'LOCATION:'.$this->escape((string) $event->location);
            }

            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\;'], trim(strip_tags($value)));
    }

    /**
     * iCalendar lines are at most 75 octets; longer ones continue on the
     * next line, indented by a space.
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        return implode("\r\n ", str_split($line, 73));
    }

    /**
     * @return array<string, mixed>
     */
    public function structuredData(Event $event): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $event->title,
            'startDate' => $event->all_day ? $event->starts_at->toDateString() : $event->starts_at->toIso8601String(),
            'endDate' => $event->ends_at === null ? null : ($event->all_day ? $event->ends_at->toDateString() : $event->ends_at->toIso8601String()),
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'eventStatus' => 'https://schema.org/EventScheduled',
            'description' => Str::limit(trim(strip_tags((string) ($event->summary ?: $event->body))), 300),
            'url' => url($event->publicPath()),
            'location' => filled($event->location) ? ['@type' => 'Place', 'name' => $event->location, 'address' => $event->location] : null,
            'organizer' => ['@id' => url('/').'#organization'],
        ], fn ($value): bool => $value !== null && $value !== '');
    }
}
