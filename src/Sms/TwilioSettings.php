<?php

namespace Gadya\Cms\Sms;

use Gadya\Cms\Options\Options;

/**
 * The client's own Twilio account, which sends the site's text alerts.
 *
 * Entered under Settings → Text messages and kept like every other key a
 * client owns: in the site's options, the auth token encrypted with the
 * application key, never in config or .env and never shown back. Gadya
 * Media sends no texts for anyone: the account, the number and the bill
 * are the client's.
 */
class TwilioSettings
{
    public const API = 'https://api.twilio.com/2010-04-01/Accounts/';

    public function __construct(private readonly Options $options) {}

    public function accountSid(): ?string
    {
        return $this->string('sms.twilio.sid');
    }

    public function authToken(): ?string
    {
        return $this->options->getSecret('sms.twilio.token');
    }

    /** The number texts come from, in E.164. */
    public function from(): ?string
    {
        return $this->string('sms.twilio.from');
    }

    public function messagingServiceSid(): ?string
    {
        return $this->string('sms.twilio.service');
    }

    public function isConfigured(): bool
    {
        return $this->accountSid() !== null && $this->authToken() !== null && ($this->from() !== null || $this->messagingServiceSid() !== null);
    }

    /**
     * @param  array{sid?: string|null, token?: string|null, from?: string|null, service?: string|null}  $data
     */
    public function save(array $data): void
    {
        $this->options->set('sms.twilio.sid', $this->clean($data['sid'] ?? null));
        $this->options->set('sms.twilio.from', PhoneNumbers::normalise($data['from'] ?? null));
        $this->options->set('sms.twilio.service', $this->clean($data['service'] ?? null));

        /* A blank token means "keep the one I gave you": it is never shown back. */
        if (filled($data['token'] ?? null)) {
            $this->options->setSecret('sms.twilio.token', trim((string) $data['token']));
        }
    }

    public function forget(): void
    {
        foreach (['sms.twilio.sid', 'sms.twilio.from', 'sms.twilio.service'] as $key) {
            $this->options->forget($key);
        }

        $this->options->setSecret('sms.twilio.token', null);
    }

    public function messagesUrl(): string
    {
        return self::API.rawurlencode((string) $this->accountSid()).'/Messages.json';
    }

    private function string(string $key): ?string
    {
        $value = $this->options->get($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function clean(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
