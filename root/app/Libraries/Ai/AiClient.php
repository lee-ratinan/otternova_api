<?php

namespace App\Libraries\Ai;

/**
 * Asks a language model for an answer. The features call this through service('ai') and never know which client is
 * behind it: FakeAiClient (fixtures, no network) or GeminiClient (Google AI). See Config\Ai.
 */
interface AiClient
{
    /**
     * @throws AiException when there is no usable answer (timed out, rate limited, blocked, unreadable, ...)
     */
    public function generate(AiRequest $request): AiResult;
}
