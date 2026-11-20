<?php

namespace App\Libraries\Ai;

use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use Config\Ai;
use JsonException;

/**
 * An AiClient that calls the Google AI (Gemini) API, models/{model}:generateContent (ai.driver = gemini). Kept thin:
 * it turns an AiRequest into the API's request, and its answer or error into an AiResult or an AiException. The key
 * goes in the x-goog-api-key header and is never logged.
 */
class GeminiClient implements AiClient
{
    /** Finish reasons that mean the answer was withheld by a filter */
    private const BLOCKED_REASONS = ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'IMAGE_SAFETY'];

    public function __construct(private readonly Ai $config, private readonly CURLRequest $http)
    {
    }

    public function generate(AiRequest $request): AiResult
    {
        if (trim($this->config->googleApiKey) === '') {
            throw new AiException(AiException::NOT_CONFIGURED, 'No Google AI key (ai.googleApiKey)');
        }
        $model = $this->config->models[$request->feature] ?? $this->config->defaultModel;
        $url   = rtrim($this->config->geminiBaseUrl, '/') . '/models/' . rawurlencode($model) . ':generateContent';

        $started = hrtime(true);
        try {
            $response = $this->http->post($url, [
                'headers' => [
                    'x-goog-api-key' => $this->config->googleApiKey,
                    'Content-Type'   => 'application/json',
                ],
                'body'            => json_encode(self::body($request), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'timeout'         => $this->config->timeout,
                'connect_timeout' => $this->config->connectTimeout,
                'http_errors'     => false,
            ]);
        } catch (HTTPException $e) {
            // cURL error 28 is a timeout; the message is "<errno> : <error>"
            $kind = preg_match('/^\s*28\b/', $e->getMessage()) === 1 ? AiException::TIMEOUT : AiException::UNAVAILABLE;

            throw new AiException($kind, 'Gemini could not be reached: ' . $e->getMessage(), $e);
        }
        $latencyMs = intdiv(hrtime(true) - $started, 1_000_000);

        $status = $response->getStatusCode();
        try {
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = null;
        }
        if ($status !== 200) {
            throw self::httpError($status, is_array($data) ? (string) ($data['error']['message'] ?? '') : '');
        }
        if (! is_array($data)) {
            throw new AiException(AiException::BAD_RESPONSE, 'Gemini answered HTTP 200 with no JSON');
        }

        if (isset($data['promptFeedback']['blockReason'])) {
            throw new AiException(AiException::BLOCKED, 'Gemini blocked the prompt: ' . $data['promptFeedback']['blockReason']);
        }
        $candidate    = $data['candidates'][0] ?? null;
        $finishReason = (string) ($candidate['finishReason'] ?? '');
        if (in_array($finishReason, self::BLOCKED_REASONS, true)) {
            throw new AiException(AiException::BLOCKED, 'Gemini withheld the answer: ' . $finishReason);
        }
        $text = '';
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (isset($part['text']) && empty($part['thought'])) {
                $text .= $part['text'];
            }
        }

        $usage = $data['usageMetadata'] ?? [];

        return AiResult::fromText(
            $request,
            $text,
            (string) ($data['modelVersion'] ?? $model),
            (int) ($usage['promptTokenCount'] ?? 0),
            (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0),
            $finishReason,
            $latencyMs,
        );
    }

    /**
     * The generateContent request body
     * @return array<string, mixed>
     */
    private static function body(AiRequest $request): array
    {
        $body = ['contents' => [['role' => 'user', 'parts' => [['text' => $request->prompt]]]]];
        if ($request->systemInstruction !== null && $request->systemInstruction !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $request->systemInstruction]]];
        }
        $generation = [];
        if ($request->temperature !== null) {
            $generation['temperature'] = $request->temperature;
        }
        if ($request->maxOutputTokens !== null) {
            $generation['maxOutputTokens'] = $request->maxOutputTokens;
        }
        if ($request->wantsJson()) {
            $generation['responseMimeType']   = 'application/json';
            $generation['responseJsonSchema'] = $request->responseSchema;
        }
        if ($generation !== []) {
            $body['generationConfig'] = $generation;
        }

        return $body;
    }

    private static function httpError(int $status, string $message): AiException
    {
        $kind = match (true) {
            $status === 429                   => AiException::RATE_LIMITED,
            $status === 408, $status === 504  => AiException::TIMEOUT,
            $status === 401, $status === 403  => AiException::NOT_CONFIGURED,
            $status >= 500                    => AiException::UNAVAILABLE,
            default                           => AiException::INVALID_REQUEST,
        };

        return new AiException($kind, 'Gemini answered HTTP ' . $status . ($message !== '' ? ': ' . $message : ''));
    }
}
