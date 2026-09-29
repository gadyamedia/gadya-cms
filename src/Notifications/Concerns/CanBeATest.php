<?php

namespace Gadya\Cms\Notifications\Concerns;

use Gadya\Cms\Mail\PortalTransport;
use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Mime\Email;

/**
 * What makes an email a test sent from the panel: a subject that starts
 * "[Test]", one plain line saying nobody has written in, and a header the
 * portal turns into a marker in its sent list. Everything else about the
 * message is left exactly as a real one, which is the point of a test.
 */
trait CanBeATest
{
    public static function testBanner(): string
    {
        return 'This is a test - nobody has written in.';
    }

    protected function testSubject(string $subject): string
    {
        return $this->test ? '[Test] '.$subject : $subject;
    }

    protected function markTest(MailMessage $mail): MailMessage
    {
        if (! $this->test) {
            return $mail;
        }

        return $mail->withSymfonyMessage(function (Email $message): void {
            $message->getHeaders()->addTextHeader(PortalTransport::PURPOSE_HEADER, 'test');
        });
    }
}
