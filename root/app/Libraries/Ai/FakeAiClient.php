<?php

namespace App\Libraries\Ai;

use Config\Ai;

/**
 * An AiClient that calls nothing: for local copies, the tests and load tests (ai.driver = fake). It answers like the
 * real one, so everything above the client runs for real:
 *
 * - The same request always gets the same answer. Answers come from, in order: those given to respondWith(); the
 *   fixture file <feature>.json in Config\Ai::$fakeFixturePath (a list of answers, one picked by the prompt); for a
 *   request with a schema, JSON made up to fit the schema; otherwise a short text naming the feature.
 * - Token counts are made up (about four characters a token), so usage logs and quotas have something to count.
 * - Failures can be asked for: [[fake:<kind>]] in the prompt (kinds of AiException, e.g. [[fake:rate_limited]]),
 *   Config\Ai::$fakeFailure for every request, or Config\Ai::$fakeFailureRate for a share of them.
 * - Config\Ai::$fakeLatencyMs delays each answer.
 * - Every request is kept, for a test to check what was asked (calls()).
 */
class FakeAiClient implements AiClient
{
    private const MARKER = '/\[\[fake:([a-z_]+)\]\]/';

    /** @var list<AiRequest> */
    private array $calls = [];

    /** @var array<string, list<string|array<array-key, mixed>>> */
    private array $answers = [];

    public function __construct(private readonly Ai $config)
    {
    }

    public function generate(AiRequest $request): AiResult
    {
        $this->calls[] = $request;
        if ($this->config->fakeLatencyMs > 0) {
            usleep($this->config->fakeLatencyMs * 1000);
        }

        $model   = 'fake/' . ($this->config->models[$request->feature] ?? $this->config->defaultModel);
        $failure = $this->failureFor($request);
        if ($failure === AiException::BAD_RESPONSE) {
            // through fromText, as a real unreadable answer would go
            return AiResult::fromText($request, $request->wantsJson() ? '{"cut short' : '', $model,
                self::tokens($request->prompt), 0, 'MAX_TOKENS', $this->config->fakeLatencyMs);
        }
        if ($failure !== null) {
            throw new AiException($failure, 'Fake ' . $failure . ' for feature ' . $request->feature);
        }

        $answer = $this->answerFor($request);
        $text   = is_array($answer) ? json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $answer;
        $input  = self::tokens(($request->systemInstruction ?? '') . $request->prompt);

        return AiResult::fromText($request, $text, $model, $input, self::tokens($text), 'STOP', $this->config->fakeLatencyMs);
    }

    /**
     * Answer $feature with these, in place of its fixture file; one is picked by the prompt
     * @param string|array<array-key, mixed> ...$answers Text, or an array sent back as JSON
     */
    public function respondWith(string $feature, string|array ...$answers): static
    {
        $this->answers[$feature] = array_values($answers);

        return $this;
    }

    /** @return list<AiRequest> Every request so far, oldest first */
    public function calls(): array
    {
        return $this->calls;
    }

    /** Forget the requests and the answers given to respondWith() */
    public function reset(): void
    {
        $this->calls   = [];
        $this->answers = [];
    }

    private function failureFor(AiRequest $request): ?string
    {
        if (preg_match(self::MARKER, $request->prompt, $match) === 1 && in_array($match[1], AiException::KINDS, true)) {
            return $match[1];
        }
        if (in_array($this->config->fakeFailure, AiException::KINDS, true)) {
            return $this->config->fakeFailure;
        }
        if ($this->config->fakeFailureRate > 0 && self::pick($request, 10000) < $this->config->fakeFailureRate * 10000) {
            return AiException::RATE_LIMITED;
        }

        return null;
    }

    /** @return string|array<array-key, mixed> */
    private function answerFor(AiRequest $request): string|array
    {
        $answers = $this->answers[$request->feature] ?? $this->fixture($request->feature);
        if ($answers !== []) {
            return $answers[self::pick($request, count($answers))];
        }
        if ($request->wantsJson()) {
            $value = self::fromSchema($request->responseSchema, $request->feature);

            return is_array($value) ? $value : [$value];
        }

        return 'Fake answer for ' . $request->feature . '.';
    }

    /** @return list<string|array<array-key, mixed>> */
    private function fixture(string $feature): array
    {
        if (preg_match('/^[a-z0-9_]+$/', $feature) !== 1) {
            return [];
        }
        $file = rtrim($this->config->fakeFixturePath, '/') . '/' . $feature . '.json';
        if (! is_file($file)) {
            return [];
        }
        $answers = json_decode((string) file_get_contents($file), true);

        return is_array($answers) && array_is_list($answers) ? $answers : [];
    }

    /** A number from 0 to $count - 1 that is always the same for the same request */
    private static function pick(AiRequest $request, int $count): int
    {
        return crc32($request->feature . "\n" . $request->prompt) % $count;
    }

    private static function tokens(string $text): int
    {
        return max(1, intdiv(strlen($text) + 3, 4));
    }

    /**
     * A value that fits a (simple) JSON Schema: object, array, string (enum, date formats), integer, number, boolean
     * @param array<string, mixed> $schema
     */
    private static function fromSchema(array $schema, string $name): mixed
    {
        if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== []) {
            return $schema['enum'][0];
        }
        $type = $schema['type'] ?? (isset($schema['properties']) ? 'object' : 'string');
        if (is_array($type)) {
            $type = array_values(array_diff($type, ['null']))[0] ?? 'null';
        }

        switch (strtolower((string) $type)) {
            case 'object':
                $object = [];
                foreach ($schema['properties'] ?? [] as $property => $propertySchema) {
                    $object[$property] = self::fromSchema(is_array($propertySchema) ? $propertySchema : [], (string) $property);
                }

                return $object;

            case 'array':
                $count = max(1, (int) ($schema['minItems'] ?? 2));
                $count = min($count, (int) ($schema['maxItems'] ?? $count));
                $items = [];
                for ($i = 1; $i <= $count; $i++) {
                    $items[] = self::fromSchema(is_array($schema['items'] ?? null) ? $schema['items'] : [], $name . ' ' . $i);
                }

                return $items;

            case 'integer':
                return (int) ($schema['minimum'] ?? 1);

            case 'number':
                return (float) ($schema['minimum'] ?? 1.5);

            case 'boolean':
                return true;

            case 'null':
                return null;

            default:
                return match ($schema['format'] ?? null) {
                    'date'      => '2026-01-01',
                    'date-time' => '2026-01-01T00:00:00Z',
                    'email'     => 'fake@example.com',
                    'uri'       => 'https://example.com/',
                    default     => 'Fake ' . str_replace('_', ' ', $name),
                };
        }
    }
}
