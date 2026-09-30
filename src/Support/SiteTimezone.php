<?php

namespace Gadya\Cms\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Gadya\Cms\Options\Options;

/**
 * The business's own time zone, for everything the CMS shows or decides by
 * the clock.
 *
 * Storage never changes: every timestamp stays in `app.timezone` (UTC), so
 * existing rows keep their meaning. This is only about the words drawn for
 * a person, a wall-clock time a person types, and "what day is it" - the
 * day a report starts on, the day a digest counts.
 *
 * The zone is the `site.timezone` option, else `gadya-cms.timezone`
 * (`GADYA_TIMEZONE`), else `app.timezone`. A value that is not a real
 * zone is skipped, so a typo can never break a page.
 */
class SiteTimezone
{
    public const OPTION = 'site.timezone';

    /**
     * The zones most of Gadya Media's clients are in, offered first.
     *
     * @var array<string, string>
     */
    public const COMMON = [
        'America/New_York' => 'Eastern - New York',
        'America/Chicago' => 'Central - Chicago',
        'America/Denver' => 'Mountain - Denver',
        'America/Los_Angeles' => 'Pacific - Los Angeles',
        'America/Phoenix' => 'Arizona - Phoenix (no daylight saving)',
        'America/Anchorage' => 'Alaska - Anchorage',
        'Pacific/Honolulu' => 'Hawaii - Honolulu',
    ];

    /** @var array<string, true>|null */
    private static ?array $identifiers = null;

    public function __construct(private readonly Options $options) {}

    /**
     * The zone in use, as an identifier such as "America/New_York".
     */
    public function name(): string
    {
        return $this->explicitName() ?? $this->storageName();
    }

    /**
     * The zone the business or the site's config chose, or null when
     * neither did and the application's own zone applies.
     */
    public function explicitName(): ?string
    {
        $chosen = rescue(fn (): mixed => $this->options->get(self::OPTION), null, report: false);

        foreach ([$chosen, config('gadya-cms.timezone')] as $candidate) {
            if (is_string($candidate) && self::isValid($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The zone the site would use with nothing chosen: the configured one,
     * else the application's.
     */
    public function defaultName(): string
    {
        $configured = config('gadya-cms.timezone');

        return is_string($configured) && self::isValid($configured) ? $configured : $this->storageName();
    }

    /**
     * Whether the person has chosen a zone of their own.
     */
    public function isChosen(): bool
    {
        $chosen = rescue(fn (): mixed => $this->options->get(self::OPTION), null, report: false);

        return is_string($chosen) && self::isValid($chosen);
    }

    /**
     * The name for a schedule's `->timezone()`, which is built before any
     * container is at hand.
     */
    public static function current(): string
    {
        return app(self::class)->name();
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->name());
    }

    /**
     * A moment as the business sees it. A string is a stored value, so it
     * is read in the storage zone (or by its own offset, if it has one).
     */
    public function local(CarbonInterface|string|null $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $moment = is_string($value)
            ? CarbonImmutable::parse($value, $this->storageName())
            : CarbonImmutable::instance($value);

        return $moment->setTimezone($this->name());
    }

    /**
     * What a person typed as a clock time on the business's wall, ready to
     * store. A string is read in the site's zone (unless it carries an
     * offset of its own); a Carbon is already a moment and is only moved.
     * Returned in the storage zone.
     */
    public function toStorage(CarbonInterface|string|null $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $moment = is_string($value)
            ? CarbonImmutable::parse($value, $this->name())
            : CarbonImmutable::instance($value);

        return $moment->setTimezone($this->storageName());
    }

    /**
     * A clock time kept without a zone - an event's "6pm" - as the moment
     * it means on the business's wall. The digits are kept; only the zone
     * is given, so they never shift when the zone is changed to another
     * that the digits were typed for.
     */
    public function atWallClock(CarbonInterface $wallClock): CarbonImmutable
    {
        return CarbonImmutable::parse($wallClock->format('Y-m-d H:i:s.u'), $this->name());
    }

    /**
     * The first moment of the business's day, in the storage zone. Given
     * nothing it is today; given a string it is that date on the business's
     * calendar; given a Carbon it is the day that moment falls on there.
     */
    public function startOfLocalDay(CarbonInterface|string|null $day = null): CarbonImmutable
    {
        return $this->day($day)->startOfDay()->setTimezone($this->storageName());
    }

    /**
     * The last moment of the business's day, in the storage zone.
     */
    public function endOfLocalDay(CarbonInterface|string|null $day = null): CarbonImmutable
    {
        return $this->day($day)->endOfDay()->setTimezone($this->storageName());
    }

    /**
     * A stored moment, drawn in the business's time. Empty when there is
     * nothing to draw.
     */
    public function format(CarbonInterface|string|null $value, string $format, string $empty = ''): string
    {
        return $this->local($value)?->format($format) ?? $empty;
    }

    /**
     * Keep a chosen zone, or forget it with null (the default applies).
     * Anything that is not a real zone is ignored.
     */
    public function set(?string $zone): void
    {
        if ($zone !== null && $zone !== '' && ! self::isValid($zone)) {
            return;
        }

        $this->options->set(self::OPTION, $zone === '' ? null : $zone);
    }

    public static function isValid(string $zone): bool
    {
        self::$identifiers ??= array_fill_keys(DateTimeZone::listIdentifiers(), true);

        return isset(self::$identifiers[$zone]);
    }

    /**
     * Every zone as Select options: the common US zones first, then all
     * the others grouped by region.
     *
     * @return array<string, array<string, string>>
     */
    public static function groupedOptions(): array
    {
        $groups = ['Common US time zones' => self::COMMON];

        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            if (isset(self::COMMON[$identifier])) {
                continue;
            }

            $region = str_contains($identifier, '/') ? strstr($identifier, '/', true) : 'Other';
            $groups[$region][$identifier] = str_replace('_', ' ', $identifier);
        }

        return $groups;
    }

    /**
     * The storage zone: whatever the application keeps its timestamps in
     * (UTC), which this class never changes.
     */
    private function storageName(): string
    {
        $zone = config('app.timezone');

        return is_string($zone) && $zone !== '' ? $zone : 'UTC';
    }

    private function day(CarbonInterface|string|null $day): CarbonImmutable
    {
        return match (true) {
            $day === null || $day === '' => $this->now(),
            is_string($day) => CarbonImmutable::parse($day, $this->name()),
            default => $this->local($day) ?? $this->now(),
        };
    }
}
