<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\ArtifactMasterModel;
use App\Models\ArtifactReviewModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * The reviews of a business's artifacts (artifact_review): the Client app's, behind the jwt:C filter, for any active
 * client of the business, its employees included (ManagedBusinessTrait::ALL_ROLES), not only its owners and managers.
 * A review is sent as ArtifactReviewModel::payloads() gives it. Approving or hiding one works out its artifact's
 * rating again.
 */
class ArtifactReviews extends BaseController
{
    use ManagedBusinessTrait;

    /**
     * POST /client/artifact-reviews { business_slug, start, length, filters: { review_status, artifact_public_id } }
     * One page of the reviews of that business's artifacts, newest first: { records_total, records_filtered, rows },
     * with business_rating, the rating of all its artifacts together: { rating_count, rating_average,
     * rating_breakdown: { "1": reviews, ..., "5": reviews } }, the approved reviews only, whatever the filters.
     * start is 0 and length 25 when left out (at most 500); every filter is optional.
     * @return ResponseInterface
     */
    public function list(): ResponseInterface
    {
        $endpoint = 'v1/client/artifact-reviews';
        $input    = $this->jsonInput();
        if ($input === null || ! is_array($input['filters'] ?? [])) {
            return ApiResponse::error(lang('ArtifactReview.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'ArtifactReview', self::ALL_ROLES);
        if ($error !== null) {
            return $error;
        }
        $start     = filter_var($input['start'] ?? null, FILTER_VALIDATE_INT);
        $length    = filter_var($input['length'] ?? null, FILTER_VALIDATE_INT);
        $start     = $start === false ? 0 : max(0, $start);
        $length    = $length === false ? 25 : min(500, max(1, $length));
        $filtersIn = $input['filters'] ?? [];
        $filters   = [];
        $status    = is_string($filtersIn['review_status'] ?? null) ? strtoupper(trim($filtersIn['review_status'])) : '';
        if (in_array($status, [ArtifactReviewModel::STATUS_PENDING, ArtifactReviewModel::STATUS_APPROVED, ArtifactReviewModel::STATUS_HIDDEN], true)) {
            $filters['review_status'] = $status;
        }
        try {
            if (is_string($filtersIn['artifact_public_id'] ?? null) && $filtersIn['artifact_public_id'] !== '') {
                $artifact               = model(ArtifactMasterModel::class)->findByPublicId($filtersIn['artifact_public_id']);
                $filters['artifact_id'] = $artifact !== null && (int) $artifact['business_id'] === (int) $business['id'] ? (int) $artifact['id'] : 0; // 0: none
            }
            $reviews = model(ArtifactReviewModel::class);
            $data    = $reviews->listOfBusiness((int) $business['id'], $start, $length, $filters)
                + ['business_rating' => $reviews->ratingOfBusiness((int) $business['id'])];
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'ArtifactReview.loadFailed');
        }

        return ApiResponse::success($data);
    }

    /**
     * POST /client/artifact-review/approve { public_id }
     * Shows the review to other customers and counts it towards its artifact's rating
     * @return ResponseInterface { review }
     */
    public function approve(): ResponseInterface
    {
        return $this->setStatus(ArtifactReviewModel::STATUS_APPROVED, 'v1/client/artifact-review/approve', 'ArtifactReview.approved');
    }

    /**
     * POST /client/artifact-review/hide { public_id }
     * Takes the review down, and out of its artifact's rating
     * @return ResponseInterface { review }
     */
    public function hide(): ResponseInterface
    {
        return $this->setStatus(ArtifactReviewModel::STATUS_HIDDEN, 'v1/client/artifact-review/hide', 'ArtifactReview.hidden');
    }

    /**
     * approve() and hide(); a review with that status already is left as it is, and answered the same
     * @param string $status
     * @param string $endpoint
     * @param string $message Language key
     * @return ResponseInterface
     */
    private function setStatus(string $status, string $endpoint, string $message): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('ArtifactReview.invalidRequest'), 422);
        }
        $publicId = $input['public_id'] ?? null;
        if (! is_string($publicId) || $publicId === '') {
            return ApiResponse::error(lang('ArtifactReview.reviewIdRequired'), 400);
        }
        $reviews = model(ArtifactReviewModel::class);
        try {
            $review   = $reviews->findByPublicId($publicId);
            $artifact = $review === null ? null : model(ArtifactMasterModel::class)->find((int) $review['artifact_id']);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'ArtifactReview.loadFailed');
        }
        // a review of a business the client is not in is as unknown as one that is not there
        $error = $artifact === null
            ? ApiResponse::error(lang('ArtifactReview.notFound'), 404)
            : $this->roleError((int) $artifact['business_id'], $endpoint, lang('ArtifactReview.notFound'), 'ArtifactReview', self::ALL_ROLES);
        if ($error !== null) {
            return $error;
        }

        try {
            $reviews->setStatus($review, $status, $this->actorId());
            $payload = $reviews->payloads([(int) $review['id']])[0];
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'ArtifactReview.saveFailed');
        }

        return ApiResponse::success(['review' => $payload], 200, lang($message));
    }

    /**
     * auth_master.id of the signed-in account, for log_activity.changed_by_acc
     * @return int
     */
    private function actorId(): int
    {
        return (int) service('authContext')->account()['id'];
    }

    /**
     * The JSON body as an array: an empty body counts as {}, and anything that is not a JSON object is null
     * @return array|null
     */
    private function jsonInput(): ?array
    {
        if (trim((string) $this->request->getBody()) === '') {
            return [];
        }

        try {
            $input = $this->request->getJSON(true);
        } catch (HTTPException) {
            return null;
        }

        return is_array($input) && ($input === [] || ! array_is_list($input)) ? $input : null;
    }

    /**
     * @param string    $endpoint For the error log
     * @param Throwable $e
     * @param string    $message  Language key shown to the user
     * @return ResponseInterface
     */
    private function failed(string $endpoint, Throwable $e, string $message): ResponseInterface
    {
        log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

        return ApiResponse::error(lang($message), 500);
    }
}
