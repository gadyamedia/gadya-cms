<?php

namespace Gadya\Cms\Mail;

use Illuminate\Support\Str;

/**
 * "Who is this sent by?" - what a client (or whoever is setting the site
 * up) needs to know before relying on its notifications: who the sender
 * will be, where a reply goes, whether there is room to send, what has
 * gone out lately, and anything about the set-up that looks wrong, each
 * with a one-line fix.
 *
 * Asked of the portal only when describing, on a screen someone opened -
 * never while a message is on its way, which is why fromLine() reads
 * nothing but what the site already knows.
 */
class SenderPanel
{
    public const VIA_SHARED = 'shared';

    public const VIA_OWN = 'own';

    /** Mailers that never leave the server. */
    private const GOES_NOWHERE = ['log', 'array'];

    public function __construct(
        private readonly SharedSender $sender,
        private readonly PortalMail $portal,
    ) {}

    /**
     * @return array{
     *     via: string,
     *     transport: string,
     *     from_name: string,
     *     from_address: string,
     *     from: string,
     *     reply_to: string,
     *     visitor_replies: string,
     *     allowance: string|null,
     *     recent: list<array<string, mixed>>,
     *     recent_note: string|null,
     *     warnings: list<array{problem: string, fix: string}>,
     * }
     */
    public function describe(bool $fresh = false): array
    {
        $shared = $this->sender->enabled();
        $status = $shared ? $this->portal->status($fresh) : null;

        $name = $shared ? $this->sender->name() : (string) config('mail.from.name');
        $address = $shared ? (string) ($status['address'] ?? $this->sender->address()) : (string) config('mail.from.address');
        $replyTo = $shared ? $this->sender->replyTo() : null;

        return [
            'via' => $shared ? self::VIA_SHARED : self::VIA_OWN,
            'transport' => $this->transport($shared),
            'from_name' => $name,
            'from_address' => $address,
            'from' => $this->line($name, $address),
            'reply_to' => $replyTo ?? ($shared ? 'nobody in particular - set one under Settings → Enquiry emails' : ($address !== '' ? $address : 'the From address')),
            'visitor_replies' => 'Whoever filled the form in, so replying goes straight to them.',
            'allowance' => $shared && isset($status['sent_this_hour'], $status['per_hour']) ? "{$status['sent_this_hour']} of {$status['per_hour']} emails this hour" : null,
            'recent' => $shared ? array_values((array) ($status['recent'] ?? [])) : [],
            'recent_note' => $shared ? null : 'This site\'s own mail service does not tell the site what it has sent. Look in its own dashboard to see what was delivered.',
            'warnings' => $this->warnings($shared, $status, $address),
        ];
    }

    /**
     * "Site Name <hello@on.gadya.media>", from what the site already
     * knows: safe to call while a message is being written.
     */
    public function fromLine(): string
    {
        return $this->sender->enabled()
            ? $this->line($this->sender->name(), $this->sender->address())
            : $this->line((string) config('mail.from.name'), (string) config('mail.from.address'));
    }

    /**
     * Whether a message sent now goes nowhere: the log and array mailers
     * write it down and stop.
     */
    public function goesNowhere(): bool
    {
        return ! $this->sender->enabled() && in_array((string) config('mail.default'), self::GOES_NOWHERE, true);
    }

    /**
     * What to do about a reason a send failed, in a line.
     */
    public function fixFor(string $reason): string
    {
        $reason = Str::lower($reason);

        return match (true) {
            str_contains($reason, 'not connected') => 'Pair this site with Gadya Media under Gadya Support, or set MAIL_MAILER to the site\'s own mail service.',
            str_contains($reason, 'switched off') => 'Email for this site is switched off at Gadya Media. Email help@support.gadya.media and ask for it to be switched on.',
            str_contains($reason, 'addressed to at most') => 'Send it to fewer people at once - a message can go to 25 at most.',
            str_contains($reason, 'hourly') || str_contains($reason, 'for the hour') || str_contains($reason, 'too many') => 'Wait an hour and try again. If it keeps happening something on the site may be sending in a loop, so tell Gadya Media.',
            str_contains($reason, 'could not be reached') || str_contains($reason, 'timed out') || str_contains($reason, 'connection') => 'The mail service could not be reached. Try again in a minute; if it keeps failing, check the site\'s internet connection and mail settings.',
            str_contains($reason, 'invalid') || str_contains($reason, 'email address') || str_contains($reason, 'does not comply') => 'Check the address is written correctly, for example name@business.com.',
            str_contains($reason, 'authenticat') || str_contains($reason, 'credentials') || str_contains($reason, 'username') => 'The mail service turned down the site\'s login. Check the mail username and password in the site\'s .env.',
            default => 'Check the mail settings in the site\'s .env, or email help@support.gadya.media with the reason above.',
        };
    }

    private function transport(bool $shared): string
    {
        if ($shared) {
            return 'Sent by Gadya Media ('.trim((string) config('gadya-cms.mail.domain', 'on.gadya.media'), " \t@.").')';
        }

        $default = (string) config('mail.default');
        $transport = (string) (config("mail.mailers.{$default}.transport") ?: $default);

        return "Sent by this site's own mail service ({$transport})";
    }

    private function line(string $name, string $address): string
    {
        return $address === '' ? 'no address yet' : ($name !== '' ? "{$name} <{$address}>" : $address);
    }

    /**
     * @param  array<string, mixed>|null  $status
     * @return list<array{problem: string, fix: string}>
     */
    private function warnings(bool $shared, ?array $status, string $address): array
    {
        $warnings = [];
        $mailer = (string) config('mail.default');

        if ($shared) {
            if ($status === null) {
                $warnings[] = ['problem' => 'Gadya Media could not be asked about this site\'s email just now, so what is shown is a best guess.', 'fix' => 'Try again in a minute. If it keeps happening, email help@support.gadya.media.'];
            } elseif (($status['enabled'] ?? true) === false) {
                $warnings[] = ['problem' => 'Sending is switched off for this site at Gadya Media, so no email will go out.', 'fix' => 'Email help@support.gadya.media and ask for it to be switched on.'];
            }

            return $warnings;
        }

        if ($this->sender->connection() === null && (in_array($mailer, [...self::GOES_NOWHERE, ''], true) || $mailer === SharedSender::MAILER)) {
            $warnings[] = ['problem' => 'This site is not paired with Gadya Media, so it has no way to send email.', 'fix' => 'Pair it under Gadya Support, or set MAIL_MAILER to the site\'s own mail service.'];
        }

        if ($address === '') {
            $warnings[] = ['problem' => 'No From address is set, so email may be turned away or sent as no one.', 'fix' => 'Set MAIL_FROM_ADDRESS in the site\'s .env to an address on the business\'s own domain.'];
        }

        if (in_array($mailer, self::GOES_NOWHERE, true) && app()->environment('production')) {
            $warnings[] = ['problem' => "The site's mailer is \"{$mailer}\", which writes email to a file instead of sending it, and this is the live site.", 'fix' => 'Set MAIL_MAILER to the site\'s mail service (smtp, ses, postmark...) in .env, or pair the site with Gadya Media.'];
        }

        $from = $this->domainOf($address);
        $site = $this->siteDomain();

        if ($from !== null && $site !== null && $from !== $site && ! str_ends_with($from, '.'.$site) && ! str_ends_with($site, '.'.$from)) {
            $warnings[] = ['problem' => "Email is sent from {$from}, which is not this site's domain ({$site}). Mail like that is often marked as spam.", 'fix' => "Send from an address at {$site} (MAIL_FROM_ADDRESS), and make sure the mail service is set up to send for that domain."];
        }

        return $warnings;
    }

    private function domainOf(string $address): ?string
    {
        $domain = Str::after($address, '@');

        return $address !== '' && str_contains($address, '@') && $domain !== '' ? Str::lower($domain) : null;
    }

    private function siteDomain(): ?string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? (string) preg_replace('/^www\./', '', Str::lower($host)) : null;
    }
}
