<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\DocumentRenderer;
use App\Models\BusinessClientLinkModel;
use App\Models\ClientMasterModel;
use App\Models\SubscriptionInvoiceModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * The billing documents of a subscription invoice as whole HTML pages: the invoice (by its invoice_no, INV-...) and,
 * once paid, its receipt (by its receipt_no, RCT-...). Each is frozen when issued and sent exactly as it was the first
 * time (see App\Libraries\DocumentRenderer), whatever has changed since; an invoice shows no status. The apps open the
 * page in a new tab, where it prints on A4 (or saves as PDF).
 *
 * - /client/documents/*: behind jwt:C, for the active owners of the invoice's business only. Any other client gets
 *   404, as the invoice is not theirs to know of, and a manager or employee of the business 403.
 * - /staff/documents/*: behind jwt:S, for the Executive Office and Finance (the departments of staff/subscriptions).
 *
 * A document goes out as text/html, never cached, with a Content-Security-Policy that lets it load only its fonts
 * and its embedded images; an error goes out as the usual JSON.
 */
class Documents extends BaseController
{
    /** What a document page may load: its own styles, Google Fonts, and the logo and QR it embeds */
    private const CSP = "default-src 'none'; style-src 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src data:; base-uri 'none'; form-action 'none'";

    /**
     * GET /client/documents/invoice/{invoice_no}
     * @param string $invoiceNo
     * @return ResponseInterface
     */
    public function clientInvoice(string $invoiceNo): ResponseInterface
    {
        return $this->document('v1/client/documents/invoice', 'invoice', $invoiceNo, true);
    }

    /**
     * GET /client/documents/receipt/{receipt_no}
     * @param string $receiptNo
     * @return ResponseInterface
     */
    public function clientReceipt(string $receiptNo): ResponseInterface
    {
        return $this->document('v1/client/documents/receipt', 'receipt', $receiptNo, true);
    }

    /**
     * GET /staff/documents/invoice/{invoice_no}
     * @param string $invoiceNo
     * @return ResponseInterface
     */
    public function staffInvoice(string $invoiceNo): ResponseInterface
    {
        return $this->document('v1/staff/documents/invoice', 'invoice', $invoiceNo, false);
    }

    /**
     * GET /staff/documents/receipt/{receipt_no}
     * @param string $receiptNo
     * @return ResponseInterface
     */
    public function staffReceipt(string $receiptNo): ResponseInterface
    {
        return $this->document('v1/staff/documents/receipt', 'receipt', $receiptNo, false);
    }

    /**
     * @param string $endpoint For the error log
     * @param string $kind     invoice or receipt
     * @param string $number   invoice_no or receipt_no
     * @param bool   $byClient Whether only the owners of its business may see it
     * @return ResponseInterface
     */
    private function document(string $endpoint, string $kind, string $number, bool $byClient): ResponseInterface
    {
        $notFound = $kind === 'invoice' ? 'Documents.invoiceNotFound' : 'Documents.receiptNotFound';
        try {
            $invoice = model(SubscriptionInvoiceModel::class)->findByDocumentNo($kind . '_no', strtoupper(trim($number)));
            if ($invoice !== null && $byClient) {
                $client = model(ClientMasterModel::class)->findByAuthId((int) service('authContext')->account()['id']);
                $role   = $client === null ? null : model(BusinessClientLinkModel::class)->activeRole((int) $invoice['business_id'], (int) $client['id']);
                if ($role === null) {
                    $invoice = null;
                } elseif ($role !== BusinessClientLinkModel::ROLE_OWNER) {
                    return ApiResponse::error(lang('Documents.ownersOnly'), 403);
                }
            }
            // a receipt stays with its invoice_no, but only a paid invoice is shown with it
            if ($invoice !== null && $kind === 'receipt' && $invoice['invoice_status'] !== SubscriptionInvoiceModel::STATUS_PAID) {
                $invoice = null;
            }
            if ($invoice === null) {
                return ApiResponse::error(lang($notFound), 404);
            }
            $html = (new DocumentRenderer())->page($invoice, $kind);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Documents.loadFailed'), 500);
        }

        if ($html === null) {
            // its QR could not be fetched: not rendered yet, so that it is never sent once without it
            return ApiResponse::error(lang('Documents.notReady'), 503);
        }

        // removed first: the response starts with a no-store Cache-Control that setHeader() would add to
        return $this->response
            ->removeHeader('Cache-Control')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('Content-Disposition', 'inline; filename="' . $invoice[$kind . '_no'] . '.html"')
            ->setHeader('Content-Security-Policy', self::CSP)
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Referrer-Policy', 'no-referrer')
            ->setContentType('text/html', 'UTF-8')
            ->setBody($html);
    }
}
