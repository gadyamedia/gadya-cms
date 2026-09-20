<?php

namespace Gadya\Cms\Mail;

use Gadya\Connect\Models\Connection;
use Illuminate\Support\Str;

/**
 * Whether this site sends its email through Gadya Media, and as whom.
 *
 * A client who has not set up a mail service of her own still has to be
 * able to send an enquiry notification. Rather than ask her for SMTP
 * details - or leave a Cloudflare token in her .env, where it could send
 * as any address Gadya owns - the site hands the message to the portal
 * over the link gadya/connect already keeps, signed with the secret from
 * pairing. The portal is what talks to Cloudflare, so no mail credential
 * ever lives on a client's server and one site can be cut off without
 * touching the rest.
 */
class SharedSender
{
    /** The mailer, and the transport, this package registers. */
    public const MAILER = 'gadya';

    /** Mailers that mean "nothing is set up yet". */
    private const UNCONFIGURED = ['log', 'array', ''];

    private ?Connection $connection = null;

    private bool $lookedForConnection = false;

    /**
     * Auto - the default - takes over only on a paired site with no mailer
     * of its own. True insists, false never does, and either way a site
     * that is not paired has nowhere to send and is left alone.
     */
    public function enabled(): bool
    {
        $shared = config('gadya-cms.mail.shared', 'auto');

        if ($shared === 'auto') {
            /*
             * `log` is what a fresh Laravel .env says, and locally it is
             * what a developer means, so only a deployed site is taken
             * over. Anything else is a service the client chose.
             */
            $shared = in_array((string) config('mail.default'), self::UNCONFIGURED, true)
                && ! app()->environment('local', 'testing');
        }

        return filter_var($shared, FILTER_VALIDATE_BOOL) && $this->connection() !== null;
    }

    /**
     * The address the site sends from: the name before the @ comes from the
     * portal's name for the site unless one is configured here. The portal
     * has the last word - it sends from the address it holds for the site -
     * so this is what the panel can promise, not a claim on the domain.
     */
    public function address(): string
    {
        $domain = trim((string) config('gadya-cms.mail.domain', 'on.gadya.media'), " \t@.");

        return $this->mailbox().'@'.$domain;
    }

    public function mailbox(): string
    {
        $configured = config('gadya-cms.mail.mailbox');

        $name = filled($configured)
            ? (string) $configured
            : (string) ($this->connection()?->site_name ?: config('gadya-cms.brand.name') ?: config('app.name'));

        $mailbox = Str::limit(Str::slug(Str::ascii($name)), 60, '');

        return $mailbox !== '' ? $mailbox : 'site';
    }

    /** The name the message appears to come from: the business's own. */
    public function name(): string
    {
        return (string) (config('gadya-cms.seo.site_name')
            ?: config('gadya-cms.brand.name')
            ?: config('app.name'));
    }

    /**
     * Where a reply goes. Nobody reads the shared address, so a message
     * that names no Reply-To of its own is given the business's.
     */
    public function replyTo(): ?string
    {
        foreach ([config('gadya-cms.mail.reply_to'), config('gadya-cms.seo.organization.email')] as $address) {
            if (is_string($address) && filter_var($address, FILTER_VALIDATE_EMAIL)) {
                return $address;
            }
        }

        return null;
    }

    public function connection(): ?Connection
    {
        if (! $this->lookedForConnection) {
            $this->connection = Connection::current();
            $this->lookedForConnection = true;
        }

        return $this->connection;
    }

    /** Forgets the pairing this was built with, for a site that has just paired. */
    public function refresh(): void
    {
        $this->connection = null;
        $this->lookedForConnection = false;
    }
}
