<?php

namespace Gadya\Cms\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * Writes one short piece of text to order. The instructions come from
 * whoever asked, so the same agent can describe a photograph, write the
 * sentence under a page in search results, or name a link.
 */
#[MaxTokens(400)]
#[Timeout(60)]
class ShortWriter implements Agent
{
    use Promptable;

    private string $instructions = 'You write short, plain text for a small business website.';

    public function guidedBy(string $instructions): self
    {
        $this->instructions = $instructions;

        return $this;
    }

    public function instructions(): string
    {
        return $this->instructions;
    }
}
