<?php
// The notifications' titles and messages, by event_type (see App\Libraries\Notifier::EVENTS); {placeholders} come
// from notification_master.message_params. Then the endpoints' own messages.
return [
    'payment' => [
        'slip_submitted' => [
            'title'   => '待核对的付款',
            'message' => '{business} 已为发票 {invoice_no} 支付 {amount}。请核对凭证并批准。',
        ],
        'approved' => [
            'title'   => '付款已批准',
            'message' => '您为发票 {invoice_no} 支付的 {amount} 已获批准。谢谢。',
        ],
        'rejected' => [
            'title'   => '付款被拒绝',
            'message' => '您为发票 {invoice_no} 的付款被拒绝：{reason}。请重新付款。',
        ],
    ],

    'order' => [
        'cancelled' => [
            'title'   => '已取消订单需要退款',
            'message' => '订单 {order_number} 已被 {by} 取消：{reason}。已付款 {amount}。请办理退款。',
        ],
        'booked' => [
            'title'   => '新预约：{service}',
            'message' => '{customer} 预约了您在 {when} 于 {store} 的 {service}（订单 {order_number}）。付款：{payment}。',
        ],
        'seats_booked' => [
            'title'   => '又有 {seats} 个名额被预订：{session}',
            'message' => '{customer} 预订了 {when} 于 {store} 的 {session} 共 {seats} 个名额；目前已预订 {booked}/{capacity}（订单 {order_number}）。付款：{payment}。',
        ],
        'payment_reported' => [
            'title'   => '待核对的付款：{order_number}',
            'message' => '{customer} 表示已通过 {method}{reference} 为订单 {order_number} 支付 {amount}。请核对并标记为已收款。',
        ],
        'cancelled_unpaid' => [
            'title'   => '订单已取消',
            'message' => '订单 {order_number} 已被 {by} 取消：{reason}。此订单尚未付款。',
        ],
    ],

    // a booking's payment, for the people serving it
    'payment_state' => [
        'paid'     => '已付款',
        'at_store' => '到店付款',
        'awaiting' => '尚未付款，因此仍可能被取消或未到场',
    ],

    'invalidRequest' => '无法读取请求。请重新加载页面后再试。',
    'invalidStatus'  => '请选择未读或已读。',
    'notFound'       => '找不到此通知。',
    'loadFailed'     => '无法加载通知。请再试一次。',
    'saveFailed'     => '无法更新通知。请再试一次。',
    'allRead'        => '所有通知已标记为已读。',
    'deleted'        => '通知已删除。',
    'readDeleted'    => '已删除已读通知：{0}。',
];
