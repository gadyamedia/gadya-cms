<?php

namespace Gadya\Cms\Support;

use Gadya\Cms\Activity\Activity;
use Gadya\Cms\Options\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Coming soon: the site is built but nobody should see it yet, or it is
 * being worked on for an afternoon.
 *
 * Not Laravel's own maintenance mode, which takes the whole application
 * down, admin included - the point here is that the client can still get
 * in, and can hand one password to whoever needs a look.
 */
class Maintenance
{
    public const COOKIE = 'gadya-cms-preview-pass';

    public function __construct(
        private readonly Options $options,
        private readonly Activity $activity,
    ) {}

    public function isOn(): bool
    {
        if (! (bool) $this->options->get('maintenance.enabled', false)) {
            return false;
        }

        $until = $this->options->get('maintenance.until');

        if (is_string($until) && $until !== '') {
            $ends = rescue(fn (): Carbon => Carbon::parse($until), null, report: false);

            if ($ends !== null && $ends->isPast()) {
                return false;
            }
        }

        return true;
    }

    public function heading(): string
    {
        return (string) ($this->options->get('maintenance.heading') ?: 'We will be back shortly');
    }

    public function message(): string
    {
        return (string) ($this->options->get('maintenance.message') ?: 'This site is being worked on. Please try again a little later.');
    }

    public function until(): ?Carbon
    {
        $until = $this->options->get('maintenance.until');

        return is_string($until) && $until !== '' ? rescue(fn (): Carbon => Carbon::parse($until), null, report: false) : null;
    }

    public function hasPassword(): bool
    {
        return filled($this->options->get('maintenance.password'));
    }

    /**
     * Anyone who may work on the site, and anyone holding the password,
     * sees the site as usual. Everyone else gets the notice.
     */
    public function allows(Request $request): bool
    {
        if ($request->user()?->can((string) config('gadya-cms.gate', 'manage-content'))) {
            return true;
        }

        $password = $this->options->get('maintenance.password');

        if (! is_string($password) || $password === '') {
            return false;
        }

        $given = $request->input('pass') ?? $request->cookie(self::COOKIE);

        return is_string($given) && hash_equals($password, $given);
    }

    /**
     * @param  array{enabled?: bool, heading?: string|null, message?: string|null, password?: string|null, until?: string|null}  $data
     */
    public function save(array $data): void
    {
        $was = $this->isOn();

        foreach (['enabled', 'heading', 'message', 'password', 'until'] as $key) {
            if (array_key_exists($key, $data)) {
                $this->options->set('maintenance.'.$key, $data[$key]);
            }
        }

        if ($was !== $this->isOn()) {
            $this->activity->record($this->isOn() ? 'site.maintenance_on' : 'site.maintenance_off');
        }
    }

    /**
     * The link the client sends to whoever needs a look: the address with
     * the password on it, which the browser then remembers.
     */
    public function shareUrl(): ?string
    {
        $password = $this->options->get('maintenance.password');

        return is_string($password) && $password !== '' ? url('/').'?pass='.urlencode($password) : null;
    }
}
