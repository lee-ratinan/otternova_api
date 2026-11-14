<?php

namespace App\Libraries;

use App\Models\MartOrderModel;
use Throwable;

/**
 * The emails an order's customer gets that need the order itself: its confirmation once placed (on the mart or at the
 * counter), with what was ordered, the total and, while it is not paid, how to pay; its link opens the order's payment
 * page on the mart with the order's key, so the customer can pay it, and say they did, from the email. A customer who
 * gave no email address gets none. A failure is logged; the order stands.
 */
final class OrderMailer
{
    private function __construct()
    {
    }

    /**
     * The confirmation of a placed order
     * @param int    $orderId order_master.id
     * @param string $key     The key place() gave, which opens the order
     * @return bool Whether an email went out
     */
    public static function placed(int $orderId, string $key): bool
    {
        try {
            $db    = db_connect();
            $row   = $db->table('order_master o')->select('o.business_id, o.order_number, o.email_address, o.first_name, o.language_code, o.financial_status, '
                . 'o.payment_method_code, b.business_name, b.business_slug')
                ->join('business_master b', 'b.id = o.business_id')->where('o.id', $orderId)->get()->getRowArray();
            if ($row === null || trim((string) $row['email_address']) === '') {
                return false;
            }
            $mailer = service('emailService');
            $locale = $mailer->supportedLocale($row['language_code']);
            $order  = model(MartOrderModel::class)->order((int) $row['business_id'], $row['order_number'], $key, $row['language_code']);
            if ($order === null) {
                return false;
            }
            $money = static fn (?string $amount): string => $order['currency'] . ' ' . number_format((float) $amount, 2);
            $items = [];
            foreach ($order['lines'] as $line) {
                $what = $line['name'] . ($line['label'] !== '' ? ' (' . $line['label'] . ')' : '') . ' × ' . $line['quantity'];
                if ($line['starts_at'] !== null) {
                    $what .= ', ' . str_replace('T', ' ', substr($line['starts_at'], 0, 16)) . ($line['store'] !== null ? ' · ' . $line['store']['name'] : '');
                }
                $items[] = '• ' . $what . ': ' . $money($line['total']);
            }
            $paid    = $row['financial_status'] !== 'pending' && $row['financial_status'] !== 'partially_paid';
            $payment = $order['payment'];
            $how     = $payment['handler_code'] === 'promptpay_qr'
                ? lang('Email.order_placed.promptpay', [], $locale)
                : trim((string) ($payment['instruction'] ?? ''));

            return $mailer->send($paid ? EmailService::TYPE_ORDER_PLACED_PAID : EmailService::TYPE_ORDER_PLACED, $row['email_address'], [
                'first_name'    => $row['first_name'],
                'business_name' => $row['business_name'],
                'order_number'  => $row['order_number'],
                'items'         => implode("\n", $items),
                'total'         => $money($order['totals']['total']),
                'method'        => $payment['name'],
                'how'           => $how === '' ? lang('Email.order_placed.no-instruction', ['business_name' => $row['business_name']], $locale) : $how,
                'link'          => rtrim((string) env('LINK_MART', ''), '/') . '/' . rawurlencode($row['business_slug']) . '/payment/'
                    . rawurlencode($row['order_number']) . '?key=' . rawurlencode($key),
            ], $row['language_code']);
        } catch (Throwable $e) {
            log_message('error', 'Order confirmation email of order {id}: {message}', ['id' => $orderId, 'message' => $e->getMessage()]);

            return false;
        }
    }
}
