<?php

namespace Gadya\Cms\Mail;

use Gadya\Connect\Portal\PortalClient;
use Illuminate\Http\Client\Response;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Throwable;

/**
 * Sends a message by handing it to the Gadya Media portal, over the signed
 * link gadya/connect made at pairing. The portal sends it on - today
 * through Cloudflare - which is why this site needs no mail account, no
 * SMTP details and no API token of its own.
 */
class PortalTransport extends AbstractTransport
{
    public const PATH = '/api/connect/v1/mail';

    public function __construct(
        private readonly PortalClient $portal,
        private readonly SharedSender $sender,
    ) {
        parent::__construct();
    }

    public function __toString(): string
    {
        return SharedSender::MAILER;
    }

    protected function doSend(SentMessage $message): void
    {
        $connection = $this->sender->connection();

        if ($connection === null) {
            throw new TransportException('This site is not connected to Gadya Media, so it cannot send email through it. Pair it under Gadya Support, or set MAIL_MAILER to the site\'s own mail service.');
        }

        $payload = $this->payload($message);

        /*
         * A site whose queue is `sync` sends inside the visitor's own
         * request - a form submission - so the wait for the portal is a
         * wait she sits through. Shorter here than the check-in's.
         */
        $timeout = config('gadya-connect.timeout');
        config(['gadya-connect.timeout' => max(1, (int) config('gadya-cms.mail.timeout', 8))]);

        try {
            $response = $this->portal->send($connection, 'POST', self::PATH, $payload);
        } catch (Throwable $exception) {
            throw new TransportException('Gadya Media could not be reached to send the message: '.$exception->getMessage(), 0, $exception);
        } finally {
            config(['gadya-connect.timeout' => $timeout]);
        }

        if (! $response->successful()) {
            throw new TransportException($this->reason($response), $response->status());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(SentMessage $message): array
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $envelope = $message->getEnvelope();

        return array_filter([
            'from' => $this->address($email->getFrom()[0] ?? $envelope->getSender()),
            'reply_to' => $this->addresses($email->getReplyTo()),
            'to' => $this->addresses($email->getTo()),
            'cc' => $this->addresses($email->getCc()),
            'bcc' => $this->addresses($this->blindRecipients($email, $envelope)),
            'subject' => (string) $email->getSubject(),
            'html' => $this->body($email->getHtmlBody()),
            'text' => $this->body($email->getTextBody()),
            'attachments' => $this->attachments($email),
        ], fn ($value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /**
     * Everyone the envelope is addressed to who is not named in the
     * message itself: a blind copy, which only the envelope knows about.
     *
     * @return list<Address>
     */
    private function blindRecipients(Email $email, Envelope $envelope): array
    {
        $named = array_map(
            fn (Address $address): string => $address->getAddress(),
            [...$email->getTo(), ...$email->getCc()],
        );

        return array_values(array_filter(
            $envelope->getRecipients(),
            fn (Address $address): bool => ! in_array($address->getAddress(), $named, true),
        ));
    }

    /**
     * @param  list<Address>  $addresses
     * @return list<array{email: string, name?: string}>
     */
    private function addresses(array $addresses): array
    {
        return array_values(array_filter(array_map(fn (Address $address): ?array => $this->address($address), $addresses)));
    }

    /**
     * @return array{email: string, name?: string}|null
     */
    private function address(?Address $address): ?array
    {
        if ($address === null) {
            return null;
        }

        return array_filter([
            'email' => $address->getAddress(),
            'name' => $address->getName(),
        ], fn (string $value): bool => $value !== '');
    }

    /**
     * Symfony hands a body back as a string or as a stream, depending on
     * how it was set.
     */
    private function body(mixed $body): ?string
    {
        if (is_resource($body)) {
            return (string) stream_get_contents($body);
        }

        return is_string($body) && $body !== '' ? $body : null;
    }

    /**
     * @return list<array{filename: string, content_type: string, content: string}>
     */
    private function attachments(Email $email): array
    {
        $attachments = [];
        $bytes = 0;
        $limit = max(1, (int) config('gadya-cms.mail.max_attachment_megabytes', 10)) * 1024 * 1024;

        foreach ($email->getAttachments() as $attachment) {
            $body = $attachment->getBody();
            $bytes += strlen($body);

            if ($bytes > $limit) {
                throw new TransportException('The message carries more than '.(int) config('gadya-cms.mail.max_attachment_megabytes', 10).'MB of attachments, which is more than Gadya Media will send. Link to the file instead.');
            }

            $headers = $attachment->getPreparedHeaders();

            $attachments[] = array_filter([
                'filename' => (string) $headers->getHeaderParameter('Content-Disposition', 'filename'),
                'content_type' => $attachment->getMediaType().'/'.$attachment->getMediaSubtype(),
                'content' => base64_encode($body),
                /* An inline image the HTML refers to by cid:. */
                'content_id' => trim((string) $headers->getHeaderBody('Content-ID'), '<>'),
            ], fn (string $value): bool => $value !== '');
        }

        return $attachments;
    }

    private function reason(Response $response): string
    {
        $message = $response->json('message') ?? $response->json('errors.0.message');

        return is_string($message) && $message !== ''
            ? $message
            : 'Gadya Media refused the message (HTTP '.$response->status().').';
    }
}
