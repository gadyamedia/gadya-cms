<?php

namespace Gadya\Cms\Hours;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Throwable;

/**
 * When the business is open: regular weekly hours, holidays and special
 * days, in the business's own timezone.
 *
 * Stored in the site document under `hours`, in one shape that is also
 * what the portal is sent and what a Google Business Profile sync would
 * read:
 *
 *     [
 *         'timezone' => 'America/New_York',
 *         'regular' => ['mon' => [['07:00', '15:00']], 'tue' => [...], ..., 'sun' => []],
 *         'exceptions' => [['date' => '2026-12-25', 'closed' => true, 'ranges' => [], 'label' => 'Christmas Day']],
 *     ]
 *
 * A day with no ranges is closed. A range that closes at or before it
 * opens runs past midnight ("18:00"-"02:00" is a late bar's Friday), and
 * "00:00"-"00:00" is open all day. Every moment is compared as a real
 * instant, so the night the clocks change counts its hours correctly.
 */
class OpeningHours
{
    /** @var array<string, string> */
    public const DAYS = [
        'mon' => 'Monday',
        'tue' => 'Tuesday',
        'wed' => 'Wednesday',
        'thu' => 'Thursday',
        'fri' => 'Friday',
        'sat' => 'Saturday',
        'sun' => 'Sunday',
    ];

    /**
     * @param  array<string, list<array{0: string, 1: string}>>  $regular
     * @param  list<array{date: string, closed: bool, ranges: list<array{0: string, 1: string}>, label: string|null}>  $exceptions
     */
    private function __construct(
        private readonly string $timezone,
        private readonly array $regular,
        private readonly array $exceptions,
    ) {}

    /**
     * From the stored shape - or anything near it, as a form or an import
     * hands it over. Times that make no sense are dropped, not guessed at.
     *
     * @param  array<string, mixed>|null  $hours
     */
    public static function fromArray(?array $hours, ?string $fallbackTimezone = null): self
    {
        $hours ??= [];

        $regular = [];

        foreach (array_keys(self::DAYS) as $day) {
            $regular[$day] = self::normaliseRanges($hours['regular'][$day] ?? []);
        }

        $exceptions = [];

        foreach ((array) ($hours['exceptions'] ?? []) as $exception) {
            if (! is_array($exception) || ! is_string($exception['date'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $exception['date'])) {
                continue;
            }

            $ranges = self::normaliseRanges($exception['ranges'] ?? []);
            $closed = (bool) ($exception['closed'] ?? false) || $ranges === [];

            $exceptions[substr($exception['date'], 0, 10)] = [
                'date' => substr($exception['date'], 0, 10),
                'closed' => $closed,
                'ranges' => $closed ? [] : $ranges,
                'label' => filled($exception['label'] ?? null) ? trim((string) $exception['label']) : null,
            ];
        }

        ksort($exceptions);

        return new self(self::validTimezone($hours['timezone'] ?? null, $fallbackTimezone), $regular, array_values($exceptions));
    }

    /**
     * @return array{timezone: string, regular: array<string, list<array{0: string, 1: string}>>, exceptions: list<array{date: string, closed: bool, ranges: list<array{0: string, 1: string}>, label: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'timezone' => $this->timezone,
            'regular' => $this->regular,
            'exceptions' => $this->exceptions,
        ];
    }

    public function isSet(): bool
    {
        return array_filter($this->regular) !== [] || $this->exceptions !== [];
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    /**
     * @return array<string, list<array{0: string, 1: string}>>
     */
    public function regular(): array
    {
        return $this->regular;
    }

    /**
     * @return list<array{date: string, closed: bool, ranges: list<array{0: string, 1: string}>, label: string|null}>
     */
    public function exceptions(): array
    {
        return $this->exceptions;
    }

    /**
     * Holidays and special days from today, soonest first.
     *
     * @return list<array{date: string, closed: bool, ranges: list<array{0: string, 1: string}>, label: string|null}>
     */
    public function upcomingExceptions(?CarbonInterface $from = null, ?int $days = null): array
    {
        $today = $this->local($from)->toDateString();
        $until = $days === null ? null : $this->local($from)->addDays($days)->toDateString();

        return array_values(array_filter(
            $this->exceptions,
            fn (array $exception): bool => $exception['date'] >= $today && ($until === null || $exception['date'] <= $until),
        ));
    }

    /**
     * @return array{date: string, closed: bool, ranges: list<array{0: string, 1: string}>, label: string|null}|null
     */
    public function exceptionOn(CarbonInterface|string $date): ?array
    {
        $date = is_string($date) ? substr($date, 0, 10) : $this->local($date)->toDateString();

        foreach ($this->exceptions as $exception) {
            if ($exception['date'] === $date) {
                return $exception;
            }
        }

        return null;
    }

    /**
     * The hours that apply on a date, a holiday's in place of the week's.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function rangesOn(CarbonInterface|string $date): array
    {
        $day = is_string($date) ? CarbonImmutable::parse($date, $this->timezone) : $this->local($date);
        $exception = $this->exceptionOn($day->toDateString());

        if ($exception !== null) {
            return $exception['ranges'];
        }

        return $this->regular[strtolower($day->locale('en')->format('D'))] ?? [];
    }

    public function isOpenAt(?CarbonInterface $moment = null): bool
    {
        return $this->intervalAt($this->local($moment)) !== null;
    }

    /**
     * Whether it is open, and what happens next, in the words a badge
     * needs: "Open now · closes at 3pm", "Closed · opens tomorrow at 7am".
     *
     * @return array{open: bool, always: bool, closes_at: CarbonImmutable|null, opens_at: CarbonImmutable|null, closing_soon: bool, label: string, detail: string}
     */
    public function status(?CarbonInterface $at = null): array
    {
        $now = $this->local($at);
        $interval = $this->intervalAt($now);

        if ($interval !== null) {
            [, $closes] = $interval;
            $always = $closes->greaterThan($now->addDays(7));

            return [
                'open' => true,
                'always' => $always,
                'closes_at' => $always ? null : $closes,
                'opens_at' => null,
                'closing_soon' => ! $always && $now->diffInMinutes($closes) <= 60,
                'label' => 'Open now',
                'detail' => $always ? 'open 24 hours' : 'closes '.$this->when($closes, $now),
            ];
        }

        $opens = $this->nextOpening($now);

        return [
            'open' => false,
            'always' => false,
            'closes_at' => null,
            'opens_at' => $opens,
            'closing_soon' => false,
            'label' => 'Closed',
            'detail' => $opens === null ? '' : 'opens '.$this->when($opens, $now),
        ];
    }

    /**
     * Ranges as a person reads them: "7am – 3pm, 5pm – 10pm", "Closed",
     * "Open 24 hours".
     *
     * @param  list<array{0: string, 1: string}>  $ranges
     */
    public static function describe(array $ranges): string
    {
        if ($ranges === []) {
            return 'Closed';
        }

        if (count($ranges) === 1 && $ranges[0][0] === $ranges[0][1] && $ranges[0][0] === '00:00') {
            return 'Open 24 hours';
        }

        return implode(', ', array_map(fn (array $range): string => self::time($range[0]).' – '.self::time($range[1]), $ranges));
    }

    /** "07:00" as "7am", "12:00" as "noon", "00:00" as "midnight", "22:30" as "10:30pm". */
    public static function time(string $time): string
    {
        return match ($time) {
            '00:00', '24:00' => 'midnight',
            '12:00' => 'noon',
            default => (function () use ($time): string {
                [$hour, $minute] = array_map('intval', explode(':', $time));

                return ($hour % 12 === 0 ? 12 : $hour % 12).($minute === 0 ? '' : ':'.str_pad((string) $minute, 2, '0', STR_PAD_LEFT)).($hour < 12 ? 'am' : 'pm');
            })(),
        };
    }

    /**
     * The hours in schema.org's words, for the business's JSON-LD:
     * one OpeningHoursSpecification per set of days sharing a range, and
     * one per holiday or special day still to come. A closed day is given
     * as opening and closing at midnight, as Google asks.
     *
     * @return list<array<string, mixed>>
     */
    public function specification(?CarbonInterface $from = null, ?int $days = 365): array
    {
        $grouped = [];

        foreach ($this->regular as $day => $ranges) {
            foreach ($ranges as [$opens, $closes]) {
                [$opens, $closes] = self::schemaRange($opens, $closes);
                $grouped[$opens.'-'.$closes]['opens'] = $opens;
                $grouped[$opens.'-'.$closes]['closes'] = $closes;
                $grouped[$opens.'-'.$closes]['days'][] = self::DAYS[$day];
            }
        }

        $specification = array_values(array_map(fn (array $group): array => [
            '@type' => 'OpeningHoursSpecification',
            'dayOfWeek' => $group['days'],
            'opens' => $group['opens'],
            'closes' => $group['closes'],
        ], $grouped));

        foreach ($this->upcomingExceptions($from, $days) as $exception) {
            foreach ($exception['closed'] ? [['00:00', '00:00']] : array_map(fn (array $range): array => self::schemaRange(...$range), $exception['ranges']) as [$opens, $closes]) {
                $specification[] = [
                    '@type' => 'OpeningHoursSpecification',
                    'opens' => $opens,
                    'closes' => $closes,
                    'validFrom' => $exception['date'],
                    'validThrough' => $exception['date'],
                ];
            }
        }

        return $specification;
    }

    /**
     * The shape the Google Business Profile API takes for `regularHours`
     * and `specialHours`, so a sync has nothing to translate.
     *
     * @return array{regularHours: array{periods: list<array<string, mixed>>}, specialHours: array{specialHourPeriods: list<array<string, mixed>>}}
     */
    public function toGoogleBusinessProfile(?CarbonInterface $from = null): array
    {
        $names = array_keys(self::DAYS);
        $periods = [];

        foreach ($this->regular as $day => $ranges) {
            foreach ($ranges as [$opens, $closes]) {
                $overnight = $closes <= $opens || $closes === '24:00';
                $next = $names[(array_search($day, $names, true) + 1) % 7];

                $periods[] = [
                    'openDay' => strtoupper(self::DAYS[$day]),
                    'openTime' => self::googleTime($opens),
                    'closeDay' => strtoupper(self::DAYS[$overnight ? $next : $day]),
                    'closeTime' => self::googleTime($closes === '24:00' ? '00:00' : $closes),
                ];
            }
        }

        $special = [];

        foreach ($this->upcomingExceptions($from) as $exception) {
            $date = CarbonImmutable::parse($exception['date']);
            $googleDate = ['year' => $date->year, 'month' => $date->month, 'day' => $date->day];

            if ($exception['closed']) {
                $special[] = ['startDate' => $googleDate, 'endDate' => $googleDate, 'closed' => true];

                continue;
            }

            foreach ($exception['ranges'] as [$opens, $closes]) {
                $end = $closes <= $opens ? $date->addDay() : $date;

                $special[] = [
                    'startDate' => $googleDate,
                    'openTime' => self::googleTime($opens),
                    'endDate' => ['year' => $end->year, 'month' => $end->month, 'day' => $end->day],
                    'closeTime' => self::googleTime($closes === '24:00' ? '00:00' : $closes),
                ];
            }
        }

        return ['regularHours' => ['periods' => $periods], 'specialHours' => ['specialHourPeriods' => $special]];
    }

    /**
     * The open stretch containing a moment, with neighbouring stretches
     * joined - Friday until 2am running into Saturday from midnight is one
     * night, not two.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function intervalAt(CarbonImmutable $moment): ?array
    {
        foreach ($this->mergedIntervals($moment->subDays(8), 16) as [$start, $end]) {
            if ($moment->greaterThanOrEqualTo($start) && $moment->lessThan($end)) {
                return [$start, $end];
            }
        }

        return null;
    }

    private function nextOpening(CarbonImmutable $moment): ?CarbonImmutable
    {
        foreach ($this->mergedIntervals($moment->subDay(), 400) as [$start]) {
            if ($start->greaterThan($moment)) {
                return $start;
            }
        }

        return null;
    }

    /**
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function mergedIntervals(CarbonImmutable $from, int $days): array
    {
        $intervals = [];
        $day = $from->startOfDay();

        for ($i = 0; $i <= $days; $i++) {
            foreach ($this->rangesOn($day->toDateString()) as [$opens, $closes]) {
                $intervals[] = $this->interval($day->toDateString(), $opens, $closes);
            }

            $day = $day->addDay();
        }

        usort($intervals, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];

        foreach ($intervals as $interval) {
            $last = array_key_last($merged);

            if ($last !== null && $interval[0]->lessThanOrEqualTo($merged[$last][1])) {
                if ($interval[1]->greaterThan($merged[$last][1])) {
                    $merged[$last][1] = $interval[1];
                }

                continue;
            }

            $merged[] = $interval;
        }

        return $merged;
    }

    /**
     * One range on one date as two real instants. A time the clocks skip
     * (2:30am the night they go forward) lands just after the jump.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function interval(string $date, string $opens, string $closes): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i', $date.' '.$opens, $this->timezone);
        $closesNextDay = $closes === '24:00' || $closes <= $opens;
        $closeDate = $closesNextDay ? CarbonImmutable::parse($date, $this->timezone)->addDay()->toDateString() : $date;
        $end = CarbonImmutable::createFromFormat('Y-m-d H:i', $closeDate.' '.($closes === '24:00' ? '00:00' : $closes), $this->timezone);

        return [$start, $end];
    }

    /** "at 3pm", "tomorrow at 7am", "Monday at 7am", "December 26 at 7am". */
    private function when(CarbonImmutable $moment, CarbonImmutable $now): string
    {
        $time = self::time($moment->format('H:i'));

        return match (true) {
            $moment->isSameDay($now) => 'at '.$time,
            $moment->isSameDay($now->addDay()) => $moment->hour < 6 && $moment->diffInHours($now, true) < 12 ? 'at '.$time : 'tomorrow at '.$time,
            $moment->lessThan($now->addDays(7)) => $moment->locale('en')->format('l').' at '.$time,
            default => $moment->locale('en')->format('F j').' at '.$time,
        };
    }

    private function local(?CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment ?? now())->setTimezone($this->timezone);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function schemaRange(string $opens, string $closes): array
    {
        if ($opens === $closes) {
            return ['00:00', '23:59'];
        }

        return [$opens, $closes === '24:00' ? '23:59' : $closes];
    }

    /**
     * @return array{hours: int, minutes: int}
     */
    private static function googleTime(string $time): array
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return ['hours' => $hours, 'minutes' => $minutes];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function normaliseRanges(mixed $ranges): array
    {
        $normalised = [];

        foreach ((array) $ranges as $range) {
            if (! is_array($range)) {
                continue;
            }

            $opens = self::normaliseTime($range[0] ?? $range['open'] ?? null);
            $closes = self::normaliseTime($range[1] ?? $range['close'] ?? null);

            if ($opens === null || $closes === null || $opens === '24:00') {
                continue;
            }

            $normalised[] = [$opens, $closes];
        }

        usort($normalised, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $normalised;
    }

    private static function normaliseTime(mixed $time): ?string
    {
        if (! is_string($time) || ! preg_match('/^(\d{1,2}):(\d{2})/', trim($time), $match)) {
            return null;
        }

        $hour = (int) $match[1];
        $minute = (int) $match[2];

        if ($hour === 24 && $minute === 0) {
            return '24:00';
        }

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    private static function validTimezone(mixed $timezone, ?string $fallback): string
    {
        foreach ([$timezone, $fallback, config('gadya-cms.hours.timezone'), config('app.timezone'), 'UTC'] as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            try {
                new DateTimeZone($candidate);

                return $candidate;
            } catch (Throwable) {
                continue;
            }
        }

        return 'UTC';
    }
}
