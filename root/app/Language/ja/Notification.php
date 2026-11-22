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
        'placed' => [
            'title'   => '新しい注文: {order_number}',
            'message' => '{customer}様が注文 {order_number}（{amount}）をしました。商品をご用意ください。お支払い: {payment}。',
        ],
        'rescheduled' => [
            'title'   => '予約の変更: {service}',
            'message' => '{customer}様の{store}での{service}（注文 {order_number}）が{from}から{to}に変更されました。',
        ],
        'booking_cancelled' => [
            'title'   => '予約のキャンセル: {service}',
            'message' => '{customer}様の{when}、{store}での{service}（注文 {order_number}）がキャンセルされました: {reason}。',
        ],
        'payment_failed' => [
            'title'   => 'お支払いの失敗: {order_number}',
            'message' => '注文 {order_number} の{method}による{amount}のお支払いが完了しませんでした: {reason}。お客様にご連絡ください。',
        ],
    ],

    'invoice' => [
        'issued' => [
            'title'   => '新しい請求書: {invoice_no}',
            'message' => '{business}の請求書 {invoice_no}（{amount}）が発行されました。{due}までにお支払いください。',
        ],
    ],

    'subscription' => [
        'suspended' => [
            'title'   => 'サブスクリプションが停止されました',
            'message' => '{business}のサブスクリプションが停止されました。再開するには、未払いの請求書をお支払いいただくか、お問い合わせください。',
        ],
        'expiring' => [
            'title'   => 'サブスクリプションの期限が近づいています',
            'message' => '停止中の{business}のサブスクリプションは{expires}に期限切れとなり、未払いの請求書は無効になります。継続するにはお支払いください。',
        ],
        'cancelled' => [
            'title'   => 'サブスクリプションがキャンセルされました',
            'message' => '{business}のサブスクリプションがキャンセルされました。',
        ],
    ],

    'withholding' => [
        'requested' => [
            'title'   => '確認が必要な源泉徴収の申請',
            'message' => '{business}が{rule}に基づく源泉徴収を申請しました。申請を確認してください。',
        ],
        'approved' => [
            'title'   => '源泉徴収が承認されました',
            'message' => '{business}は{rule}に基づいて源泉徴収できるようになりました。今後の請求書で計算されます。',
        ],
        'rejected' => [
            'title'   => '源泉徴収が承認されませんでした',
            'message' => '{business}の源泉徴収の申請は承認されませんでした。ご不明な点はお問い合わせください。',
        ],
    ],

    'certificate' => [
        'submitted' => [
            'title'   => '確認が必要な証明書: {invoice_no}',
            'message' => '{business}が請求書 {invoice_no} の源泉徴収証明書（{amount}）を送信しました。確認してください。',
        ],
        'accepted' => [
            'title'   => '証明書が受理されました',
            'message' => '請求書 {invoice_no} の源泉徴収証明書が受理されました。ありがとうございます。',
        ],
        'rejected' => [
            'title'   => '証明書が却下されました',
            'message' => '請求書 {invoice_no} の源泉徴収証明書が却下されました: {reason}。再度送信してください。',
        ],
    ],

    'invitation' => [
        'accepted' => [
            'title'   => '{name}さんが参加しました',
            'message' => '{name}さんが招待を承諾し、{role}として参加しました。',
        ],
    ],

    'review' => [
        'submitted' => [
            'title'   => '新しいレビュー: {artifact}',
            'message' => '{customer}様が{artifact}に星{rating}つを付けました。レビューを承認するか非表示にしてください。',
        ],
    ],

    // order.payment_failed with no reason recorded
    'no_reason' => '理由の記載なし',

    // a person's role in a business, for invitation.accepted
    'client_role' => [
        'O' => 'オーナー',
        'M' => 'マネージャー',
        'E' => 'スタッフ',
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
