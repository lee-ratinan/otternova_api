<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Settings for the AI features (App\Libraries\Ai). Each can be set in .env as ai.<name>, for example:
 *
 *     ai.driver = gemini
 *     ai.googleApiKey = <key from Google AI Studio>
 *
 * The driver picks the client behind service('ai'): "fake" answers from fixtures and makes no network call (local
 * copies, the tests, load tests); "gemini" calls the Google AI (Gemini) API. Production refuses "fake" and the tests
 * refuse "gemini", see Config\Services::ai().
 */
class Ai extends BaseConfig
{
    public const DRIVER_FAKE   = 'fake';
    public const DRIVER_GEMINI = 'gemini';

    /** "fake" or "gemini"; fake by default, so a copy without settings never spends money */
    public string $driver = self::DRIVER_FAKE;

    // --- gemini ----------------------------------------------------------------------------------------------

    /** The Google AI API key; only ever in .env, never committed, never logged */
    public string $googleApiKey = '';

    public string $geminiBaseUrl = 'https://generativelanguage.googleapis.com/v1beta';

    /** The model of a feature not listed in $models */
    public string $defaultModel = 'gemini-2.5-flash';

    /**
     * Model per feature (AiRequest::$feature => model), for a feature that needs a stronger or cheaper one
     * @var array<string, string>
     */
    public array $models = [];

    /** Seconds to wait for an answer, and for the connection */
    public int $timeout        = 30;
    public int $connectTimeout = 5;

    // --- fake ------------------------------------------------------------------------------------------------

    /** Canned answers, one <feature>.json per feature holding a list of answers (text, or JSON for a schema) */
    public string $fakeFixturePath = APPPATH . 'Libraries/Ai/Fixtures/';

    /** Milliseconds the fake waits before answering, so load tests and spinners see a real-ish delay */
    public int $fakeLatencyMs = 0;

    /**
     * A failure the fake gives for every request (one of the AiException::* kinds, '' for none); a request can also
     * ask for one itself with a [[fake:<kind>]] marker in its prompt
     */
    public string $fakeFailure = '';

    /** Share of requests (0 to 1) the fake fails as rate limited, picked by the prompt so a run repeats */
    public float $fakeFailureRate = 0.0;
}
