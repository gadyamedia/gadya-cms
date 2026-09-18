<?php

namespace Gadya\Cms\Ai;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Responses\AgentResponse;

/**
 * Sends an agent's prompt through the service chosen in the panel. Every
 * agent in the package goes through here so none of them has to know
 * which provider or model is in use.
 */
class Prompter
{
    public function __construct(private readonly AiSettings $settings) {}

    /**
     * @param  list<mixed>  $attachments  Files the model should look at, for an agent that can see.
     */
    public function prompt(Agent $agent, string $prompt, array $attachments = []): AgentResponse
    {
        $provider = $this->settings->register();

        return $agent->prompt($prompt, $attachments, provider: $provider, model: $this->settings->model());
    }
}
