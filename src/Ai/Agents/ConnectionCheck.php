<?php

namespace Gadya\Cms\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * The smallest possible prompt, sent when the client presses "Test
 * connection": it proves the key, the model name and the network all
 * work before she spends real money on an article.
 */
#[MaxTokens(20)]
#[Timeout(30)]
class ConnectionCheck implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Reply with the single word OK and nothing else.';
    }
}
