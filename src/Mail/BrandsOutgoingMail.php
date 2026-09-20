<?php

namespace Gadya\Cms\Mail;

use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * What a message sent through Gadya Media carries: the site's own shared
 * address to come from, a Reply-To that reaches the business - nothing
 * reads the shared mailbox - and one quiet line at the foot saying who
 * sent it.
 */
class BrandsOutgoingMail
{
    /** In the HTML, so the line is never added twice. */
    private const MARKER = 'data-gadya-mail';

    public function __construct(private readonly SharedSender $sender) {}

    public function handle(MessageSending $event): void
    {
        if (! $this->sender->enabled()) {
            return;
        }

        $this->stampSender($event->message);
        $this->addFooter($event->message);
    }

    /**
     * The message goes out as the site's shared address whatever it was
     * addressed from: the shared domain is the only one Gadya can send
     * for. An address of the site's own is not lost - it becomes the
     * Reply-To, which is where the client wanted the answer anyway.
     */
    private function stampSender(Email $message): void
    {
        $shared = new Address($this->sender->address(), $this->sender->name());
        $from = $message->getFrom()[0] ?? null;
        $domain = '@'.trim((string) config('gadya-cms.mail.domain', 'on.gadya.media'), " \t@.");

        if ($from !== null && ! str_ends_with(strtolower($from->getAddress()), strtolower($domain))) {
            $message->from($shared);

            if ($message->getReplyTo() === []) {
                $message->replyTo($from);
            }
        } elseif ($from === null) {
            $message->from($shared);
        }

        $replyTo = $this->sender->replyTo();

        if ($message->getReplyTo() === [] && $replyTo !== null) {
            $message->replyTo(new Address($replyTo, $this->sender->name()));
        }
    }

    private function addFooter(Email $message): void
    {
        if (! config('gadya-cms.mail.footer', true)) {
            return;
        }

        $html = $message->getHtmlBody();

        if (is_string($html) && $html !== '' && ! str_contains($html, self::MARKER)) {
            $line = view('gadya-cms::mail.powered-by', ['marker' => self::MARKER])->render();

            $message->html($this->insert($html, $line));
        }

        $text = $message->getTextBody();

        if (is_string($text) && $text !== '' && ! str_contains($text, 'gadya.media')) {
            $message->text(rtrim($text)."\n\nEmail for this site is sent by Gadya Media - https://gadya.media\n");
        }
    }

    /** Just inside the end of the message, or at the end of what there is. */
    private function insert(string $html, string $line): string
    {
        $position = strripos($html, '</body>');

        return $position === false
            ? $html.$line
            : substr($html, 0, $position).$line.substr($html, $position);
    }
}
