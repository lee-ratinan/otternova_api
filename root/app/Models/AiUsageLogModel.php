<?php

namespace App\Models;

use App\Libraries\Ai\AiException;
use App\Libraries\Ai\AiRequest;
use App\Libraries\Ai\AiResult;
use App\Libraries\Ai\AiUsageContext;
use CodeIgniter\Model;

/**
 * Model for the ai_usage_log table: one row per call to the AI client, written through record() (by
 * App\Libraries\Ai\RecordingAiClient). See the CreateAiUsageLogTable migration for the columns.
 */
class AiUsageLogModel extends Model
{
    public const STATUS_SUCCEEDED = 'S';
    public const STATUS_FAILED    = 'F';

    protected $table            = 'ai_usage_log';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'business_id',
        'artifact_id',
        'webpage_id',
        'feature',
        'attempt_no',
        'driver',
        'model',
        'status',
        'error_kind',
        'input_tokens',
        'output_tokens',
        'latency_ms',
        'requested_by',
    ];

    // created_at is maintained by the database; there is no updated_at
    protected $useTimestamps = false;

    protected $validationRules = [
        'business_id'   => 'required|is_natural_no_zero',
        'artifact_id'   => 'permit_empty|is_natural_no_zero',
        'webpage_id'    => 'permit_empty|is_natural_no_zero',
        'feature'       => 'required|max_length[64]',
        'attempt_no'    => 'permit_empty|is_natural_no_zero',
        'driver'        => 'required|in_list[fake,gemini]',
        'model'         => 'required|max_length[64]',
        'status'        => 'required|in_list[S,F]',
        'error_kind'    => 'permit_empty|max_length[32]',
        'input_tokens'  => 'is_natural',
        'output_tokens' => 'is_natural',
        'latency_ms'    => 'is_natural',
        'requested_by'  => 'permit_empty|is_natural_no_zero',
    ];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /**
     * Log one call: its result, or the exception it failed with. A success gets the next attempt_no of its target,
     * feature and driver; a failure is not an attempt and gets none.
     * @param int $latencyMs How long the call took, as the caller measured it
     * @return int The new row's id
     */
    public function record(AiRequest $request, AiUsageContext $context, string $driver, ?AiResult $result,
        ?AiException $error, int $latencyMs): int
    {
        $succeeded = $result !== null;
        $row       = [
            'business_id'   => $context->businessId,
            'artifact_id'   => $context->artifactId,
            'webpage_id'    => $context->webpageId,
            'feature'       => $request->feature,
            'attempt_no'    => $succeeded ? $this->successCount($context, $request->feature, $driver) + 1 : null,
            'driver'        => $driver,
            'model'         => mb_substr($result->model ?? $error?->model ?? 'unknown', 0, 64),
            'status'        => $succeeded ? self::STATUS_SUCCEEDED : self::STATUS_FAILED,
            'error_kind'    => $succeeded ? null : ($error?->kind ?? 'unknown'),
            'input_tokens'  => $result->inputTokens ?? $error?->inputTokens ?? 0,
            'output_tokens' => $result->outputTokens ?? $error?->outputTokens ?? 0,
            'latency_ms'    => max(0, $latencyMs),
            'requested_by'  => $context->requestedBy,
        ];
        if ($this->insert($row) === false) {
            throw new \RuntimeException('ai_usage_log not written: ' . implode('; ', $this->errors()));
        }

        return (int) $this->getInsertID();
    }

    /**
     * How many times this target's feature has succeeded on this driver: the attempts used. For a business-wide
     * context (no artifact, no page), the business's own business-wide calls.
     * @param string|null $since Only those from then on (inclusive, UTC 'Y-m-d H:i:s'), as for an allowance over a window;
     *                           null counts them all
     */
    public function successCount(AiUsageContext $context, string $feature, string $driver = 'gemini', ?string $since = null): int
    {
        $builder = $this->builder()
            ->where('feature', $feature)
            ->where('driver', $driver)
            ->where('status', self::STATUS_SUCCEEDED);
        if ($since !== null) {
            $builder->where('created_at >=', $since);
        }
        if ($context->artifactId !== null) {
            $builder->where('artifact_id', $context->artifactId);
        } elseif ($context->webpageId !== null) {
            $builder->where('webpage_id', $context->webpageId);
        } else {
            $builder->where('business_id', $context->businessId)->where('artifact_id', null)->where('webpage_id', null);
        }

        return $builder->countAllResults();
    }

    /**
     * A business's tokens on this driver from $since (inclusive, UTC 'Y-m-d H:i:s')
     * @return array{input_tokens: int, output_tokens: int}
     */
    public function businessTokens(int $businessId, string $since, string $driver = 'gemini'): array
    {
        $row = $this->builder()
            ->select('COALESCE(SUM(input_tokens), 0) AS input_tokens, COALESCE(SUM(output_tokens), 0) AS output_tokens')
            ->where('business_id', $businessId)
            ->where('driver', $driver)
            ->where('created_at >=', $since)
            ->get()->getRowArray();

        return ['input_tokens' => (int) $row['input_tokens'], 'output_tokens' => (int) $row['output_tokens']];
    }
}
