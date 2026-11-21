<?php
// The notifications' titles and messages, by event_type (see App\Libraries\Notifier::EVENTS); {placeholders} come
// from notification_master.message_params. Then the endpoints' own messages.
return [
    'payment' => [
        'slip_submitted' => [
            'title'   => '確認が必要なお支払い',
            'message' => '{business}が請求書 {invoice_no} に対して{amount}を支払いました。振込明細を確認して承認してください。',
        ],
        'approved' => [
            'title'   => 'お支払いが承認されました',
            'message' => '請求書 {invoice_no} に対する{amount}のお支払いが承認されました。ありがとうございます。',
        ],
        'rejected' => [
            'title'   => 'お支払いが却下されました',
            'message' => '請求書 {invoice_no} に対するお支払いは却下されました：{reason}。もう一度お支払いください。',
        ],
    ],

    'order' => [
        'cancelled' => [
            'title'   => 'キャンセルされた注文の返金が必要です',
            'message' => '注文 {order_number} は{by}によってキャンセルされました：{reason}。{amount}が支払われています。返金してください。',
        ],
        'booked' => [
            'title'   => '新しい予約：{service}',
            'message' => '{customer}さんが{when}に{store}で{service}を予約しました（注文 {order_number}）。お支払い：{payment}。',
        ],
        'seats_booked' => [
            'title'   => 'さらに{seats}席が予約されました：{session}',
            'message' => '{customer}さんが{when}に{store}の{session}を{seats}席予約しました。現在{capacity}席中{booked}席が埋まっています（注文 {order_number}）。お支払い：{payment}。',
        ],
        'payment_reported' => [
            'title'   => '確認が必要なお支払い：{order_number}',
            'message' => '{customer}さんから、注文 {order_number} の{amount}を{method}{reference}で支払ったとの連絡がありました。確認して受領済みにしてください。',
        ],
        'cancelled_unpaid' => [
            'title'   => '注文がキャンセルされました',
            'message' => '注文 {order_number} は{by}によってキャンセルされました：{reason}。お支払いはありませんでした。',
        ],
    ],

    // a booking's payment, for the people serving it
    'payment_state' => [
        'paid'     => '支払い済み',
        'at_store' => '店舗で支払い',
        'awaiting' => '未払いのため、キャンセルや来店なしの可能性があります',
    ],

    'invalidRequest' => 'リクエストを読み取れませんでした。ページを再読み込みして、もう一度お試しください。',
    'invalidStatus'  => '未読または既読を選択してください。',
    'notFound'       => 'この通知が見つかりませんでした。',
    'loadFailed'     => '通知を読み込めませんでした。もう一度お試しください。',
    'saveFailed'     => '通知を更新できませんでした。もう一度お試しください。',
    'allRead'        => 'すべての通知を既読にしました。',
    'deleted'        => '通知を削除しました。',
    'readDeleted'    => '既読の通知を削除しました：{0}件。',
];
