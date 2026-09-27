<?php

namespace Gadya\Cms\Portal\Commands;

use Gadya\Cms\Portal\ChangeRequests;
use Illuminate\Support\Str;

/**
 * `content.request`: the client asked for a change in the portal. The
 * site's AI drafts it, strictly into the draft, and whoever looks after
 * the site is asked to check it. Throws - failing the command, with the
 * reason shown in the portal - when there is nothing sensible to change.
 */
class RequestContentChange
{
    public function __construct(private readonly ChangeRequests $requests) {}

    public function type(): string
    {
        return 'content.request';
    }

    /**
     * @param  array<string, mixed>  $payload  request_id, instructions, page_url, requested_by
     * @return array{output: string, result: array<string, mixed>}
     */
    public function handle(array $payload): array
    {
        $result = $this->requests->draft($payload);
        $count = count($result['changes']);

        return [
            'output' => Str::limit('Drafted '.$count.' '.Str::plural('change', $count).' on '.$result['page_title'].'. Nothing is live until someone publishes it.', 5000, ''),
            'result' => $result,
        ];
    }
}
