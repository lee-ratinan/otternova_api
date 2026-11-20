<?php

namespace App\Libraries\Ai;

/**
 * One question to the model
 */
final class AiRequest
{
    /**
     * @param string                    $feature           Which feature asks (snake_case, e.g. "artifact_description");
     *                                                      picks the model (Config\Ai::$models) and the fake's fixtures,
     *                                                      and is what usage is counted by
     * @param string                    $prompt            The user turn
     * @param string|null               $systemInstruction How the model should behave
     * @param array<string, mixed>|null $responseSchema    A JSON Schema the answer must follow; with one, the answer is
     *                                                      JSON and AiResult::$json holds it decoded
     * @param float|null                $temperature       null: the model's default
     * @param int|null                  $maxOutputTokens   null: the model's default
     */
    public function __construct(
        public readonly string $feature,
        public readonly string $prompt,
        public readonly ?string $systemInstruction = null,
        public readonly ?array $responseSchema = null,
        public readonly ?float $temperature = null,
        public readonly ?int $maxOutputTokens = null,
    ) {
    }

    public function wantsJson(): bool
    {
        return $this->responseSchema !== null;
    }
}
