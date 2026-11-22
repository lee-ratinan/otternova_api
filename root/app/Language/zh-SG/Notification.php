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
        'placed' => [
            'title'   => '新订单：{order_number}',
            'message' => '{customer} 下了订单 {order_number}，金额 {amount}。请准备商品。付款：{payment}。',
        ],
        'rescheduled' => [
            'title'   => '预约已改期：{service}',
            'message' => '{customer} 在 {store} 的 {service}（订单 {order_number}）已从 {from} 改到 {to}。',
        ],
        'booking_cancelled' => [
            'title'   => '预约已取消：{service}',
            'message' => '{customer} 于 {when} 在 {store} 的 {service}（订单 {order_number}）已取消：{reason}。',
        ],
        'payment_failed' => [
            'title'   => '付款失败：{order_number}',
            'message' => '订单 {order_number} 通过 {method} 支付的 {amount} 未成功：{reason}。请跟进顾客。',
        ],
    ],

    'invoice' => [
        'issued' => [
            'title'   => '新发票：{invoice_no}',
            'message' => '{business} 的发票 {invoice_no}（{amount}）已开出。请在 {due} 前付款。',
        ],
    ],

    'subscription' => [
        'suspended' => [
            'title'   => '订阅已暂停',
            'message' => '{business} 的订阅已暂停。请支付到期的发票或联系我们以重新启用。',
        ],
        'expiring' => [
            'title'   => '订阅即将到期',
            'message' => '{business} 已暂停的订阅将于 {expires} 到期，未付的发票将作废。请付款以继续使用。',
        ],
        'cancelled' => [
            'title'   => '订阅已取消',
            'message' => '{business} 的订阅已取消。',
        ],
    ],

    'withholding' => [
        'requested' => [
            'title'   => '待审核的预扣税申请',
            'message' => '{business} 申请按 {rule} 预扣税。请审核该申请。',
        ],
        'approved' => [
            'title'   => '预扣税已批准',
            'message' => '{business} 现在可以按 {rule} 预扣税，今后的发票将计算预扣税。',
        ],
        'rejected' => [
            'title'   => '预扣税未获批准',
            'message' => '{business} 的预扣税申请未获批准。如有疑问请联系我们。',
        ],
    ],

    'certificate' => [
        'submitted' => [
            'title'   => '待核对的证明：{invoice_no}',
            'message' => '{business} 已提交发票 {invoice_no} 的预扣税证明（{amount}）。请核对。',
        ],
        'accepted' => [
            'title'   => '证明已接受',
            'message' => '发票 {invoice_no} 的预扣税证明已被接受。谢谢。',
        ],
        'rejected' => [
            'title'   => '证明被拒绝',
            'message' => '发票 {invoice_no} 的预扣税证明被拒绝：{reason}。请重新提交。',
        ],
    ],

    'invitation' => [
        'accepted' => [
            'title'   => '{name} 已加入',
            'message' => '{name} 已接受您的邀请，以{role}身份加入。',
        ],
    ],

    'review' => [
        'submitted' => [
            'title'   => '新评价：{artifact}',
            'message' => '{customer} 给 {artifact} 打了 {rating} 星。请批准或隐藏该评价。',
        ],
    ],

    // order.payment_failed with no reason recorded
    'no_reason' => '未说明原因',

    // a person's role in a business, for invitation.accepted
    'client_role' => [
        'O' => '业主',
        'M' => '经理',
        'E' => '员工',
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
