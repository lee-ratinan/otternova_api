<?php

namespace App\Libraries\Ai;

use JsonException;

/**
 * The model's answer, and what it cost
 */
final class AiResult
{
    /**
     * @param string                    $text         The answer as the model wrote it
     * @param array<array-key, mixed>|null $json      The answer decoded, when the request had a schema
     * @param string                    $model        The model that answered
     * @param int                       $inputTokens  Tokens of the prompt
     * @param int                       $outputTokens Tokens of the answer (thinking included)
     * @param string                    $finishReason As the provider gives it, e.g. "STOP"
     * @param int                       $latencyMs    How long the call took
     */
    public function __construct(
        public readonly string $text,
        public readonly ?array $json,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly string $finishReason,
        public readonly int $latencyMs,
    ) {
    }

    /**
     * The result of the model's text for $request: decoded when the request wants JSON, which fails the same way
     * for every client
     * @throws AiException (BAD_RESPONSE, carrying the tokens, which are billed all the same) when the request wants
     *                     JSON and the text is not a JSON object or list, or when the text is empty
     */
    public static function fromText(AiRequest $request, string $text, string $model, int $inputTokens, int $outputTokens,
        string $finishReason, int $latencyMs): self
    {
        $json = null;
        if ($request->wantsJson()) {
            try {
                $json = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new AiException(AiException::BAD_RESPONSE, 'The answer is not valid JSON', null, $model, $inputTokens, $outputTokens);
            }
            if (! is_array($json)) {
                throw new AiException(AiException::BAD_RESPONSE, 'The answer is not a JSON object or list', null, $model, $inputTokens, $outputTokens);
            }
        } elseif (trim($text) === '') {
            throw new AiException(AiException::BAD_RESPONSE, 'The answer is empty', null, $model, $inputTokens, $outputTokens);
        }

        return new self($text, $json, $model, $inputTokens, $outputTokens, $finishReason, $latencyMs);
    }
}
