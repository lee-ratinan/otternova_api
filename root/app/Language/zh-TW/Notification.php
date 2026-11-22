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
        'placed' => [
            'title'   => '新訂單：{order_number}',
            'message' => '{customer} 下了訂單 {order_number}，金額 {amount}。請準備商品。付款：{payment}。',
        ],
        'rescheduled' => [
            'title'   => '預約已改期：{service}',
            'message' => '{customer} 在 {store} 的 {service}（訂單 {order_number}）已從 {from} 改到 {to}。',
        ],
        'booking_cancelled' => [
            'title'   => '預約已取消：{service}',
            'message' => '{customer} 於 {when} 在 {store} 的 {service}（訂單 {order_number}）已取消：{reason}。',
        ],
        'payment_failed' => [
            'title'   => '付款失敗：{order_number}',
            'message' => '訂單 {order_number} 透過 {method} 支付的 {amount} 未成功：{reason}。請與顧客聯繫。',
        ],
    ],

    'invoice' => [
        'issued' => [
            'title'   => '新帳單：{invoice_no}',
            'message' => '{business} 的帳單 {invoice_no}（{amount}）已開立。請在 {due} 前付款。',
        ],
    ],

    'subscription' => [
        'suspended' => [
            'title'   => '訂閱已暫停',
            'message' => '{business} 的訂閱已暫停。請支付到期的帳單或聯絡我們以重新啟用。',
        ],
        'expiring' => [
            'title'   => '訂閱即將到期',
            'message' => '{business} 已暫停的訂閱將於 {expires} 到期，未付的帳單將作廢。請付款以繼續使用。',
        ],
        'cancelled' => [
            'title'   => '訂閱已取消',
            'message' => '{business} 的訂閱已取消。',
        ],
    ],

    'withholding' => [
        'requested' => [
            'title'   => '待審核的扣繳申請',
            'message' => '{business} 申請依 {rule} 扣繳稅款。請審核該申請。',
        ],
        'approved' => [
            'title'   => '扣繳已核准',
            'message' => '{business} 現在可以依 {rule} 扣繳稅款，今後的帳單將計算扣繳。',
        ],
        'rejected' => [
            'title'   => '扣繳未獲核准',
            'message' => '{business} 的扣繳申請未獲核准。如有疑問請聯絡我們。',
        ],
    ],

    'certificate' => [
        'submitted' => [
            'title'   => '待核對的憑單：{invoice_no}',
            'message' => '{business} 已提交帳單 {invoice_no} 的扣繳憑單（{amount}）。請核對。',
        ],
        'accepted' => [
            'title'   => '憑單已接受',
            'message' => '帳單 {invoice_no} 的扣繳憑單已被接受。謝謝。',
        ],
        'rejected' => [
            'title'   => '憑單被退回',
            'message' => '帳單 {invoice_no} 的扣繳憑單被退回：{reason}。請重新提交。',
        ],
    ],

    'invitation' => [
        'accepted' => [
            'title'   => '{name} 已加入',
            'message' => '{name} 已接受您的邀請，以{role}身分加入。',
        ],
    ],

    'review' => [
        'submitted' => [
            'title'   => '新評價：{artifact}',
            'message' => '{customer} 給 {artifact} {rating} 顆星。請核准或隱藏該評價。',
        ],
    ],

    // order.payment_failed with no reason recorded
    'no_reason' => '未說明原因',

    // a person's role in a business, for invitation.accepted
    'client_role' => [
        'O' => '負責人',
        'M' => '經理',
        'E' => '員工',
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
