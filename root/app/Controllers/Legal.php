<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\LegalDocumentModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * The Terms of Service and the Privacy Policy: the versions in effect, and the signed-in client accepting them.
 * See App\Models\LegalDocumentModel.
 */
class Legal extends BaseController
{
    /**
     * GET or POST /legal/current { audience }, no sign-in needed: the version of each document in effect now, with its
     * text in the request's language (English when it has none), for the sign-up page to show and send back as
     * legal_versions of /create-client-account. audience B (the Client app's, when left out) or M (the marketplace's).
     * @return ResponseInterface {documents: [{public_id, audience, document_type, version_label, ..., body}]}
     */
    public function current(): ResponseInterface
    {
        $audience = $this->audience();
        if ($audience === null) {
            return ApiResponse::error(lang('Legal.documentNotFound'), 404);
        }
        $documents = model(LegalDocumentModel::class)->currentWithText($audience, $this->request->getLocale());

        return ApiResponse::success(['documents' => $documents]);
    }

    /**
     * GET or POST /legal/document { type: T|P, label, audience: B|M }, no sign-in needed: for the website's (audience
     * B, when left out) or the marketplace's (M) page of a document, the
     * version with that label (the current one when label is left out), with its text in the request's language, and
     * every version that is or was in effect, newest first, to link to the earlier ones. A label that never was in
     * effect, scheduled ones included, is not found (404).
     * @return ResponseInterface {document: {..., body}, versions: [{public_id, version_label, effective_from}]}
     */
    public function document(): ResponseInterface
    {
        $input = $this->jsonInput() + $this->request->getGet();
        $type  = is_string($input['type'] ?? null) ? $input['type'] : '';
        $label = is_string($input['label'] ?? null) ? trim($input['label']) : '';
        if (! in_array($type, LegalDocumentModel::TYPES, true) || ($label !== '' && ! preg_match(LegalDocumentModel::LABEL_PATTERN, $label))) {
            return ApiResponse::error(lang('Legal.documentNotFound'), 404);
        }

        $legal    = model(LegalDocumentModel::class);
        $audience = $this->audience();
        if ($audience === null) {
            return ApiResponse::error(lang('Legal.documentNotFound'), 404);
        }
        $version  = $label === '' ? ($legal->currentVersions($audience)[$type] ?? null) : $legal->publishedByLabel($audience, $type, $label);
        if ($version === null) {
            return ApiResponse::error(lang('Legal.documentNotFound'), 404);
        }

        return ApiResponse::success([
            'document' => $legal->toPayload($version, $legal->textOf((int) $version['id'], $this->request->getLocale()), true),
            'versions' => $legal->publishedVersions($audience, $type),
        ]);
    }

    /**
     * POST /client/legal/pending, behind the jwt filter: the versions the signed-in client must still accept, as
     * "legal_pending" of the sign-in response
     * @return ResponseInterface {legal_pending: [...]}
     */
    public function pending(): ResponseInterface
    {
        return ApiResponse::success(['legal_pending' => $this->pendingOfSignedIn()]);
    }

    /**
     * POST /client/legal/accept { versions: [public_id, ...] }, behind the jwt filter
     * Records that the signed-in client accepted these versions, each of which must be in effect now (409 otherwise:
     * a newer one took effect since the page was shown). Accepting one again changes nothing.
     * @return ResponseInterface {legal_pending: what is still left to accept}
     */
    public function accept(): ResponseInterface
    {
        $given = $this->jsonInput()['versions'] ?? null;
        if (! is_array($given) || $given === [] || array_filter($given, 'is_string') !== $given) {
            return ApiResponse::error(lang('Auth.legalNothingAccepted'), 422);
        }

        $legal   = model(LegalDocumentModel::class);
        $current = array_column($legal->currentVersions(LegalDocumentModel::AUDIENCE_BUSINESS), null, 'public_id');
        $chosen  = array_values(array_intersect_key($current, array_flip($given)));
        if (count($chosen) !== count(array_unique($given))) {
            return ApiResponse::error(lang('Auth.legalOutdated'), 409);
        }

        $authId = (int) service('authContext')->account()['id'];
        try {
            $legal->recordAcceptances($chosen, ['auth_id' => $authId], $this->request->getLocale(), LegalDocumentModel::CONTEXT_REACCEPT,
                $this->request->getIPAddress(), $this->request->getHeaderLine('User-Agent'));
        } catch (Throwable $e) {
            log_message('error', 'legal/accept: {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Auth.legalAcceptFailed'), 500);
        }

        return ApiResponse::success(['legal_pending' => $this->pendingOfSignedIn()], 200, lang('Auth.legalAccepted'));
    }

    /**
     * @return list<array>
     */
    private function pendingOfSignedIn(): array
    {
        $authId = (int) service('authContext')->account()['id'];

        return model(LegalDocumentModel::class)->pendingForAccount($authId, $this->request->getLocale());
    }

    /**
     * The audience asked for in the body or the query: B when left out, null when it is not one
     * @return string|null
     */
    private function audience(): ?string
    {
        $value = ($this->jsonInput() + $this->request->getGet())['audience'] ?? LegalDocumentModel::AUDIENCE_BUSINESS;

        return is_string($value) && in_array($value, LegalDocumentModel::AUDIENCES, true) ? $value : null;
    }

    /**
     * The JSON body as an array; anything that is not a JSON object counts as empty
     * @return array
     */
    private function jsonInput(): array
    {
        try {
            $input = $this->request->getJSON(true);
        } catch (HTTPException) {
            return [];
        }

        return is_array($input) ? $input : [];
    }
}
