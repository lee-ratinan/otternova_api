<?php

namespace App\Libraries\Ai;

use App\Models\AiUsageLogModel;
use LogicException;
use Throwable;

/**
 * Wraps the real client (fake or Gemini) behind service('ai') and logs every call to ai_usage_log: its tokens, the
 * business and the artifact or page it was for, and, on a success, its attempt number. A failed call is logged too
 * (with the tokens of an answer that came back unusable), then its exception goes on to the caller.
 *
 * Every request needs an AiUsageContext, so no call goes unaccounted for. A failure to write the log is logged and
 * does not fail the call: the answer has been paid for already.
 */
class RecordingAiClient implements AiClient
{
    public function __construct(
        private readonly AiClient $inner,
        private readonly AiUsageLogModel $log,
        private readonly string $driver,
    ) {
    }

    public function generate(AiRequest $request): AiResult
    {
        $context = $request->context ?? throw new LogicException(
            'AI request for feature ' . $request->feature . ' has no AiUsageContext; every call is logged against a business');

        $started = hrtime(true);
        try {
            $result = $this->inner->generate($request);
        } catch (AiException $e) {
            $this->write($request, $context, null, $e, $started);

            throw $e;
        }
        $this->write($request, $context, $result, null, $started);

        return $result;
    }

    /** The wrapped client; in tests, the FakeAiClient, to give it answers and check its calls */
    public function inner(): AiClient
    {
        return $this->inner;
    }

    /** How many attempts (successes) a target's feature has used on this client's driver */
    public function attemptsUsed(AiUsageContext $context, string $feature): int
    {
        return $this->log->successCount($context, $feature, $this->driver);
    }

    private function write(AiRequest $request, AiUsageContext $context, ?AiResult $result, ?AiException $error, int $started): void
    {
        try {
            $this->log->record($request, $context, $this->driver, $result, $error, intdiv(hrtime(true) - $started, 1_000_000));
        } catch (Throwable $e) {
            log_message('error', 'AI usage not logged for business {business}, feature {feature}: {message}',
                ['business' => $context->businessId, 'feature' => $request->feature, 'message' => $e->getMessage()]);
        }
    }
}
