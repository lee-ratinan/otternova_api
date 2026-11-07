<?php

namespace App\Libraries;

use App\Models\PaymentTransactionModel;
use App\Models\SubscriptionInvoiceModel;
use Config\Billing as BillingConfig;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * The documents of a subscription_invoice row as whole HTML pages: the invoice, and the receipt once it is paid.
 * Each reads the same every time it is opened (billing_document, see CreateBillingDocumentTable):
 * - freeze() keeps what it prints, worked out when the invoice is made or paid
 * - page() renders that once, with the template version stored on the invoice (invoice_version_id,
 *   receipt_version_id: a view at app/Views/documents/{version}.php) and the QR, and keeps the page it sent
 * New invoices get the versions of invoiceVersion() and receiptVersion().
 *
 * The templates only print: every value comes worked out, as text they escape. The page is self-contained but for
 * its fonts: the logo is in the template and the QR is embedded as a data URI.
 */
class DocumentRenderer
{
    /** Where the templates are, under app/Views */
    public const TEMPLATE_PATH = 'documents/';

    /** billing_document.document_type by the kind of document */
    private const TYPES = ['invoice' => 'I', 'receipt' => 'R'];

    /** A template version: country, kind, number (th-invoice-01) */
    private const VERSION_PATTERN = '/^[a-z]{2}-(invoice|receipt)-\d{2}$/';

    /** The QR generator's image types that are embedded */
    private const QR_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'];

    private const THAI_MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

    /** payment_method.method_code as the documents name it */
    private const METHOD_NAMES = [
        'promptpay'     => 'พร้อมเพย์ / PromptPay',
        'bank_transfer' => 'โอนเงิน / Bank transfer',
        'card'          => 'บัตรเครดิต/เดบิต / Card',
    ];

    /**
     * The invoice template of the invoices made from now on. OtterNova bills from Thailand, so every business gets
     * the Thai one, whatever its country.
     * @return string
     */
    public static function invoiceVersion(): string
    {
        return INVOICE_TH_VERSION;
    }

    /**
     * The receipt template of the invoices paid from now on; see invoiceVersion()
     * @return string
     */
    public static function receiptVersion(): string
    {
        return RECEIPT_TH_VERSION;
    }

    /**
     * Whether $version names a template of that kind that exists
     * @param mixed  $version
     * @param string $kind    invoice or receipt
     * @return bool
     */
    public static function templateExists(mixed $version, string $kind): bool
    {
        return is_string($version)
            && preg_match(self::VERSION_PATTERN, $version, $parts) === 1
            && $parts[1] === $kind
            && is_file(APPPATH . 'Views/' . self::TEMPLATE_PATH . $version . '.php');
    }

    /**
     * Freeze the document of an invoice as it is now, if it has not been: the invoice when it is made, the receipt
     * when it is paid. Called inside the transaction that makes or pays it.
     * @param array       $invoice  Stored subscription_invoice row; for a receipt, paid
     * @param string      $kind     invoice or receipt
     * @param string|null $issuedAt UTC; for an invoice, when it was made (created_at when not given)
     * @return void
     */
    public function freeze(array $invoice, string $kind, ?string $issuedAt = null): void
    {
        // billing goes on without it: the document is frozen when first opened instead, as for invoices made before
        if (! self::hasTable('freeze the ' . $kind . ' of invoice ' . $invoice['invoice_no'])) {
            return;
        }
        $db   = db_connect();
        $type = self::TYPES[$kind];
        if ($db->table('billing_document')->where('invoice_id', $invoice['id'])->where('document_type', $type)->countAllResults() > 0) {
            return;
        }
        $content = $this->document($invoice, $kind, $kind === 'invoice' ? ($issuedAt ?? $invoice['created_at']) : null);
        $db->table('billing_document')->insert((new FieldEncryption())->encryptRow('billing_document', [
            'invoice_id'    => (int) $invoice['id'],
            'document_type' => $type,
            'content_json'  => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]));
    }

    /**
     * The document as a whole HTML page, exactly as it was the first time it was opened: rendered once from what
     * was frozen (see freeze()), with the template version on the invoice and, for an invoice to pay, the PromptPay
     * QR, then stored and sent as stored from then on
     * @param array  $invoice Stored subscription_invoice row
     * @param string $kind    invoice or receipt
     * @return string|null Null when it cannot be rendered and kept yet (its QR could not be fetched, or there is no
     *                     billing_document table), so it is not sent once in a form it would not keep; to be tried again
     * @throws RuntimeException When it has no receipt or its template is missing
     */
    public function page(array $invoice, string $kind): ?string
    {
        if ($kind === 'receipt' && empty($invoice['receipt_no'])) {
            throw new RuntimeException('Invoice ' . $invoice['invoice_no'] . ' has no receipt');
        }
        if (! self::hasTable('open the ' . $kind . ' of invoice ' . $invoice['invoice_no'])) {
            return null;
        }
        $stored = $this->stored($invoice, $kind);
        if ($stored === null) {
            // made before documents were frozen: frozen now, as it is
            $this->freeze($invoice, $kind);
            $stored = $this->stored($invoice, $kind);
        }
        if ($stored['html'] !== null) {
            return $stored['html'];
        }

        $content = json_decode($stored['content_json'], true, 512, JSON_THROW_ON_ERROR);
        $qr      = null;
        if ($content['qr_request'] !== null) {
            $qr = $this->qrDataUri($content['qr_request']);
            if ($qr === null) {
                return null;
            }
        }
        $version = (string) $invoice[$kind . '_version_id'];
        if (! self::templateExists($version, $kind)) {
            throw new RuntimeException('No ' . $kind . ' template ' . $version);
        }
        // no DEBUG-VIEW comments in development: one before <!DOCTYPE> would put the page in quirks mode
        $html = view(self::TEMPLATE_PATH . $version, ['doc' => $content + ['version' => $version, 'qr' => $qr]], ['saveData' => false, 'debug' => false]);

        // only the first render is kept: another request may have rendered it meanwhile
        $row = (new FieldEncryption())->encryptRow('billing_document', ['html' => $html]);
        db_connect()->table('billing_document')->where('id', $stored['id'])->where('html IS NULL')
            ->update($row + ['html_sha256' => hash('sha256', $html), 'rendered_at' => SubscriptionBilling::now()]);

        return $this->stored($invoice, $kind)['html'];
    }

    /**
     * Whether the billing_document table is there; when it is not (the CreateBillingDocumentTable migration has not
     * been run), says so in the log, with what could not be done
     * @param string $what For the log
     * @return bool
     */
    private static function hasTable(string $what): bool
    {
        if (db_connect()->tableExists('billing_document')) {
            return true;
        }
        log_message('critical', 'Could not {what}: there is no billing_document table. Run "php spark migrate" (CreateBillingDocumentTable).', ['what' => $what]);

        return false;
    }

    /**
     * @param array  $invoice
     * @param string $kind
     * @return array|null The billing_document row, decrypted
     */
    private function stored(array $invoice, string $kind): ?array
    {
        $row = db_connect()->table('billing_document')->where('invoice_id', $invoice['id'])->where('document_type', self::TYPES[$kind])->get()->getRowArray();

        return $row === null ? null : (new FieldEncryption())->decryptRow('billing_document', $row);
    }

    /**
     * Everything a template prints, worked out as it is now: amounts with thousands separators, dates in Thai and
     * English in the business's timezone, the customer's address in lines. Nothing that changes later, such as the
     * invoice's status, is in it. qr_request is what the QR of an invoice to pay is made from, when it is rendered.
     * @param array       $invoice  Stored row
     * @param string      $kind     invoice or receipt
     * @param string|null $issuedAt UTC, when the invoice was made; null for a receipt, which prints when it was paid
     * @return array
     */
    private function document(array $invoice, string $kind, ?string $issuedAt): array
    {
        $db       = db_connect();
        $business = $db->table('business_master b')
            ->select('b.business_name, b.legal_name, b.contact_person_name, b.contact_address_line_1, b.contact_address_line_2, b.contact_city')
            ->select('b.contact_subdivision_code, b.contact_postal_code, b.country_code, b.tax_id, b.tax_branch_code')
            ->join('subscription_master s', 's.business_id = b.id', 'inner')
            ->where('s.id', $invoice['subscription_id'])
            ->get()->getRowArray();
        $business = (new FieldEncryption())->decryptRow('business_master', $business);
        $plan     = $db->table('plan_version v')->select('v.billing_cycle, p.plan_code')
            ->join('plan_master p', 'p.id = v.plan_id', 'inner')
            ->where('v.id', $invoice['plan_version_id'])->get()->getRowArray();
        $discount = $invoice['discount_id'] === null ? null
            : $db->table('discount_master')->select('discount_code')->where('id', $invoice['discount_id'])->get()->getRow()?->discount_code;
        $parentNo = $invoice['parent_invoice_id'] === null ? null
            : $db->table('subscription_invoice')->select('invoice_no')->where('id', $invoice['parent_invoice_id'])->get()->getRow()?->invoice_no;
        $timezone = SubscriptionBilling::timezoneOf($business['country_code']);
        $currency = $invoice['currency_code'];
        $receipt  = $kind === 'receipt';
        $withheld = ! Money::isZero($invoice['wht_amount']);

        return [
            'kind'           => $kind,
            'invoice_no'     => $invoice['invoice_no'],
            'receipt_no'     => $invoice['receipt_no'] ?? null,
            'issued_on'      => $issuedAt === null ? null : self::date($issuedAt, $timezone),
            'due_on'         => self::date($invoice['due_at'], $timezone),
            'paid_on'        => $receipt ? self::date($invoice['paid_at'], $timezone) : null,
            'customer'       => self::customer($business),
            'line'           => self::line($invoice, $plan, $parentNo, $timezone),
            'currency_code'  => $currency,
            'list_price'     => self::amount($invoice['list_price']),
            'discount_code'  => $discount,
            'discount'       => self::amount($invoice['discount_amount']),
            'total'          => self::amount($invoice['amount_due']),
            'withheld'       => $withheld,
            'wht_rate'       => rtrim(rtrim(Money::format($invoice['wht_rate']), '0'), '.'),
            'wht_amount'     => self::amount($invoice['wht_amount']),
            'payable'        => self::amount($invoice['amount_payable']),
            'total_words'    => $currency === 'THB' ? AmountInWords::thaiBaht($invoice['amount_due']) : null,
            'total_words_en' => $currency === 'THB' ? AmountInWords::englishBaht($invoice['amount_due']) : null,
            'payment'        => $receipt ? $this->payment((int) $invoice['id']) : null,
            'bank'           => self::bank(),
            'qr_request'     => $receipt || Money::isZero($invoice['amount_payable']) ? null : [
                'invoice_no' => $invoice['invoice_no'],
                'amount'     => Money::format($invoice['amount_payable']),
            ],
        ];
    }

    /**
     * Who the document is to: the registered name when there is one, the address in lines and the tax ID with its
     * branch
     * @param array $business business_master row, tax_id decrypted
     * @return array{name: string, trade: string|null, attention: string|null, address: list<string>, tax_id: string|null, branch: string|null}
     */
    private static function customer(array $business): array
    {
        helper('list');
        $country     = $business['country_code'];
        $subdivision = listSupportedSubdivisions($country)[$business['contact_subdivision_code'] ?? ''] ?? null;
        $place       = array_filter([
            $business['contact_city'] ?? null,
            $subdivision === null ? null : preg_replace('/\s*\(.*\)$/u', '', $subdivision),
            $business['contact_postal_code'] ?? null,
        ], static fn ($part) => $part !== null && trim($part) !== '');
        $address = array_values(array_filter([
            $business['contact_address_line_1'] ?? null,
            $business['contact_address_line_2'] ?? null,
            $place === [] ? null : implode(' ', $place),
            $country === 'TH' ? null : (listCountries()[$country] ?? $country),
        ], static fn ($line) => $line !== null && trim($line) !== ''));
        $legal  = trim((string) ($business['legal_name'] ?? ''));
        $branch = trim((string) ($business['tax_branch_code'] ?? ''));

        return [
            'name'      => $legal !== '' ? $legal : $business['business_name'],
            'trade'     => $legal !== '' && $legal !== $business['business_name'] ? $business['business_name'] : null,
            'attention' => trim((string) ($business['contact_person_name'] ?? '')) ?: null,
            'address'   => $address,
            'tax_id'    => trim((string) ($business['tax_id'] ?? '')) ?: null,
            'branch'    => $branch === '' ? null : ($branch === '00000' ? 'สำนักงานใหญ่ / Head office' : 'สาขา / Branch ' . $branch),
        ];
    }

    /**
     * The one line of the invoice: the plan and its period, or the tax a top-up bills
     * @param array       $invoice
     * @param array       $plan     billing_cycle, plan_code
     * @param string|null $parentNo The invoice a top-up bills the tax of
     * @param string      $timezone
     * @return array{title: string, details: list<string>, amount: string}
     */
    private static function line(array $invoice, array $plan, ?string $parentNo, string $timezone): array
    {
        $name = ucfirst((string) $plan['plan_code']);
        if ($invoice['invoice_type'] === SubscriptionInvoiceModel::TYPE_TOP_UP) {
            return [
                'title'   => 'ภาษีหัก ณ ที่จ่ายที่ไม่ได้รับหนังสือรับรอง / Withholding tax without a certificate',
                'details' => [
                    'ของใบแจ้งหนี้ ' . $parentNo . ' (แผน ' . $name . ')',
                    'Withheld from invoice ' . $parentNo . ' (' . $name . ' plan)',
                ],
                'amount'  => self::amount($invoice['list_price']),
            ];
        }
        $yearly = $plan['billing_cycle'] === 'Y';
        if ($invoice['period_start'] !== null && $invoice['period_end'] !== null) {
            // period_end is excluded: the period ends the second before it
            $last    = (new DateTimeImmutable($invoice['period_end'], new DateTimeZone('UTC')))->modify('-1 second')->format('Y-m-d H:i:s');
            $start   = self::dateParts($invoice['period_start'], $timezone);
            $end     = self::dateParts($last, $timezone);
            $details = [
                'รอบการใช้บริการ: ' . $start['th'] . ' - ' . $end['th'],
                'Service period: ' . $start['en'] . ' - ' . $end['en'],
            ];
        } else {
            $details = ['รอบการใช้บริการเริ่มเมื่อชำระเงิน / The service period starts when paid'];
        }

        return [
            'title'   => 'แผน ' . $name . ' - ประเภท' . ($yearly ? 'รายปี' : 'รายเดือน') . ' / ' . $name . ' plan - ' . ($yearly ? 'annual' : 'monthly') . ' subscription',
            'details' => $details,
            'amount'  => self::amount($invoice['list_price']),
        ];
    }

    /**
     * The successful payment of a paid invoice: how it was paid; null when nothing was paid (amount_payable 0)
     * @param int $invoiceId
     * @return array{method: string, reference: string|null}|null
     */
    private function payment(int $invoiceId): ?array
    {
        $row = db_connect()->table('payment_transaction t')
            ->select('t.provider_ref, m.method_code')
            ->join('payment_method m', 'm.id = t.payment_method_id', 'inner')
            ->where('t.invoice_id', $invoiceId)
            ->where('t.txn_status', PaymentTransactionModel::STATUS_SUCCEEDED)
            ->orderBy('t.id', 'DESC')
            ->get()->getRowArray();
        if ($row === null) {
            return null;
        }

        return [
            'method'    => self::METHOD_NAMES[$row['method_code']] ?? $row['method_code'],
            'reference' => trim((string) ($row['provider_ref'] ?? '')) ?: null,
        ];
    }

    /**
     * The bank account for transfers, from Config\Billing; null for each part not set yet
     * @return array{name: string|null, account_name: string|null, account_number: string|null}
     */
    private static function bank(): array
    {
        $config = config(BillingConfig::class);

        return [
            'name'           => trim($config->bankName) ?: null,
            'account_name'   => trim($config->bankAccountName) ?: null,
            'account_number' => trim($config->bankAccountNumber) ?: null,
        ];
    }

    /**
     * The PromptPay bill payment QR of an invoice from the QR generator, as a data URI; null when there is no
     * generator or it fails. Its parameters are POSTed as a form.
     * @param array{invoice_no: string, amount: string} $request From the frozen content
     * @return string|null
     */
    protected function qrDataUri(array $request): ?string
    {
        $config = config(BillingConfig::class);
        $link   = trim($config->qrGeneratorLink);
        if ($link === '') {
            log_message('error', 'No QR generator (LINK_QR_GENERATOR) for invoice {invoice}', ['invoice' => $request['invoice_no']]);

            return null;
        }
        try {
            $response = service('curlrequest')->post($link, [
                'form_params' => [
                    'countryCode'                => 'TH',
                    'pointOfInitiation'          => 'DYNAMIC',
                    'merchantAccountInformation' => 'BILL_PAYMENT',
                    'billerId'                   => $config->promptPayBillerId,
                    'ref1'                       => $request['invoice_no'],
                    'transactionAmount'          => $request['amount'],
                ],
                'timeout'     => $config->qrGeneratorTimeout,
                'http_errors' => false,
            ]);
            $type = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
            $body = (string) $response->getBody();
            if ($response->getStatusCode() !== 200 || ! in_array($type, self::QR_TYPES, true) || $body === '') {
                log_message('error', 'QR generator returned HTTP {code} ({type}) for invoice {invoice}', ['code' => $response->getStatusCode(), 'type' => $type, 'invoice' => $request['invoice_no']]);

                return null;
            }
        } catch (Throwable $e) {
            log_message('error', 'QR generator failed for invoice {invoice}: {message}', ['invoice' => $request['invoice_no'], 'message' => $e->getMessage()]);

            return null;
        }

        return 'data:' . $type . ';base64,' . base64_encode($body);
    }

    /**
     * @param mixed $amount
     * @return string 3,190.00
     */
    private static function amount(mixed $amount): string
    {
        return number_format((float) Money::format($amount ?? '0'), 2);
    }

    /**
     * @param string $at       UTC
     * @param string $timezone
     * @return string 6 พ.ย. 2569 / 6 Nov 2026
     */
    private static function date(string $at, string $timezone): string
    {
        $parts = self::dateParts($at, $timezone);

        return $parts['th'] . ' / ' . $parts['en'];
    }

    /**
     * @param string $at       UTC
     * @param string $timezone
     * @return array{th: string, en: string} 6 พ.ย. 2569, 6 Nov 2026
     */
    private static function dateParts(string $at, string $timezone): array
    {
        $local = (new DateTimeImmutable($at, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone));

        return [
            'th' => $local->format('j') . ' ' . self::THAI_MONTHS[(int) $local->format('n') - 1] . ' ' . ((int) $local->format('Y') + 543),
            'en' => $local->format('j M Y'),
        ];
    }
}
