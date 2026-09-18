<?php

namespace Gadya\Cms\Notifications;

use Gadya\Cms\Filament\Resources\Comments\CommentResource;
use Gadya\Cms\Models\Comment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Someone replied under an article. The email carries the whole comment,
 * so it can be judged without opening the panel, and a link to where it
 * is approved or thrown away.
 */
class CommentPosted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Comment $comment) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $post = $this->comment->post;

        return (new MailMessage)
            ->subject('New comment on “'.$post?->title.'”')
            ->greeting($this->comment->author_name.' replied under “'.$post?->title.'”.')
            ->line($this->comment->body)
            ->line($this->comment->status === Comment::PENDING
                ? 'It is waiting to be approved and nobody can see it yet.'
                : 'It is already showing under the article.')
            ->action('Open the comments', CommentResource::getUrl())
            ->salutation('Counted and kept on your own site.');
    }
}
