<?php
// The notifications' titles and messages, by event_type (see App\Libraries\Notifier::EVENTS); {placeholders} come
// from notification_master.message_params. Then the endpoints' own messages.
return [
    'payment' => [
        'slip_submitted' => [
            'title'   => '待核對的付款',
            'message' => '{business} 已為帳單 {invoice_no} 支付 {amount}。請核對憑證並核准。',
        ],
        'approved' => [
            'title'   => '付款已核准',
            'message' => '您為帳單 {invoice_no} 支付的 {amount} 已核准。謝謝。',
        ],
        'rejected' => [
            'title'   => '付款遭拒',
            'message' => '您為帳單 {invoice_no} 的付款遭拒：{reason}。請重新付款。',
        ],
    ],

    'order' => [
        'cancelled' => [
            'title'   => '已取消訂單需要退款',
            'message' => '訂單 {order_number} 已由 {by} 取消：{reason}。已付款 {amount}。請辦理退款。',
        ],
        'booked' => [
            'title'   => '新預約：{service}',
            'message' => '{customer} 預約了您在 {when} 於 {store} 的 {service}（訂單 {order_number}）。付款：{payment}。',
        ],
        'seats_booked' => [
            'title'   => '又有 {seats} 個名額被預訂：{session}',
            'message' => '{customer} 預訂了 {when} 於 {store} 的 {session} 共 {seats} 個名額；目前已預訂 {booked}/{capacity}（訂單 {order_number}）。付款：{payment}。',
        ],
        'payment_reported' => [
            'title'   => '待核對的付款：{order_number}',
            'message' => '{customer} 表示已透過 {method}{reference} 為訂單 {order_number} 支付 {amount}。請核對並標記為已收款。',
        ],
        'cancelled_unpaid' => [
            'title'   => '訂單已取消',
            'message' => '訂單 {order_number} 已由 {by} 取消：{reason}。此訂單尚未付款。',
        ],
    ],

    // a booking's payment, for the people serving it
    'payment_state' => [
        'paid'     => '已付款',
        'at_store' => '到店付款',
        'awaiting' => '尚未付款，因此仍可能被取消或未到場',
    ],

    'invalidRequest' => '無法讀取請求。請重新載入頁面後再試。',
    'invalidStatus'  => '請選擇未讀或已讀。',
    'notFound'       => '找不到此通知。',
    'loadFailed'     => '無法載入通知。請再試一次。',
    'saveFailed'     => '無法更新通知。請再試一次。',
    'allRead'        => '所有通知已標示為已讀。',
    'deleted'        => '通知已刪除。',
    'readDeleted'    => '已刪除已讀通知：{0}。',
];
