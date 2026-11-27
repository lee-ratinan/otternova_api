<?php
// The notifications' titles and messages, by event_type (see App\Libraries\Notifier::EVENTS); {placeholders} come
// from notification_master.message_params. Then the endpoints' own messages.
return [
    'payment' => [
        'slip_submitted' => [
            'title'   => 'มีการชำระเงินรอตรวจสอบ',
            'message' => '{business} ชำระเงิน {amount} สำหรับใบแจ้งหนี้ {invoice_no} กรุณาตรวจสอบสลิปและอนุมัติ',
        ],
        'approved' => [
            'title'   => 'อนุมัติการชำระเงินแล้ว',
            'message' => 'การชำระเงิน {amount} สำหรับใบแจ้งหนี้ {invoice_no} ได้รับการอนุมัติแล้ว ขอบคุณ',
        ],
        'rejected' => [
            'title'   => 'การชำระเงินถูกปฏิเสธ',
            'message' => 'การชำระเงินสำหรับใบแจ้งหนี้ {invoice_no} ถูกปฏิเสธ: {reason} กรุณาชำระเงินอีกครั้ง',
        ],
    ],

    'order' => [
        'cancelled' => [
            'title'   => 'มีคำสั่งซื้อที่ยกเลิกรอคืนเงิน',
            'message' => 'คำสั่งซื้อ {order_number} ถูกยกเลิกโดย {by}: {reason} ลูกค้าชำระแล้ว {amount} กรุณาคืนเงิน',
        ],
        'booked' => [
            'title'   => 'มีการจองใหม่: {service}',
            'message' => '{customer} จอง {service} กับคุณ วันที่ {when} ที่ {store} (คำสั่งซื้อ {order_number}) การชำระเงิน: {payment}',
        ],
        'seats_booked' => [
            'title'   => 'จองเพิ่ม {seats} ที่: {session}',
            'message' => '{customer} จอง {seats} ที่ของ {session} วันที่ {when} ที่ {store} ตอนนี้จองแล้ว {booked} จาก {capacity} ที่ (คำสั่งซื้อ {order_number}) การชำระเงิน: {payment}',
        ],
        'payment_reported' => [
            'title'   => 'มีการชำระเงินรอตรวจสอบ: {order_number}',
            'message' => '{customer} แจ้งว่าชำระเงิน {amount} สำหรับคำสั่งซื้อ {order_number} ผ่าน {method}{reference} กรุณาตรวจสอบและบันทึกว่าได้รับแล้ว',
        ],
        'cancelled_unpaid' => [
            'title'   => 'คำสั่งซื้อถูกยกเลิก',
            'message' => 'คำสั่งซื้อ {order_number} ถูกยกเลิกโดย {by}: {reason} คำสั่งซื้อนี้ยังไม่ได้ชำระเงิน',
        ],
        'placed' => [
            'title'   => 'คำสั่งซื้อใหม่: {order_number}',
            'message' => '{customer} สั่งซื้อ {order_number} ยอด {amount} กรุณาเตรียมสินค้า การชำระเงิน: {payment}',
        ],
        'rescheduled' => [
            'title'   => 'เลื่อนการจอง: {service}',
            'message' => '{service} ของ {customer} ที่ {store} (คำสั่งซื้อ {order_number}) ถูกเลื่อนจาก {from} เป็น {to}',
        ],
        'booking_cancelled' => [
            'title'   => 'ยกเลิกการจอง: {service}',
            'message' => '{service} ของ {customer} วันที่ {when} ที่ {store} (คำสั่งซื้อ {order_number}) ถูกยกเลิก: {reason}',
        ],
        'payment_failed' => [
            'title'   => 'การชำระเงินไม่สำเร็จ: {order_number}',
            'message' => 'การชำระเงิน {amount} ผ่าน {method} สำหรับคำสั่งซื้อ {order_number} ไม่สำเร็จ: {reason} กรุณาติดต่อลูกค้า',
        ],
    ],

    'invoice' => [
        'issued' => [
            'title'   => 'ใบแจ้งหนี้ใหม่: {invoice_no}',
            'message' => 'ออกใบแจ้งหนี้ {invoice_no} ยอด {amount} สำหรับ {business} แล้ว กรุณาชำระภายใน {due}',
        ],
    ],

    'subscription' => [
        'suspended' => [
            'title'   => 'การสมัครใช้งานถูกระงับ',
            'message' => 'การสมัครใช้งานของ {business} ถูกระงับ กรุณาชำระใบแจ้งหนี้ที่ค้างหรือติดต่อเราเพื่อเปิดใช้งานอีกครั้ง',
        ],
        'expiring' => [
            'title'   => 'การสมัครใช้งานใกล้หมดอายุ',
            'message' => 'การสมัครใช้งานที่ถูกระงับของ {business} จะหมดอายุในวันที่ {expires} และใบแจ้งหนี้ที่ยังไม่ชำระจะถูกยกเลิก กรุณาชำระเงินเพื่อใช้งานต่อ',
        ],
        'cancelled' => [
            'title'   => 'การสมัครใช้งานถูกยกเลิก',
            'message' => 'การสมัครใช้งานของ {business} ถูกยกเลิกแล้ว',
        ],
    ],

    'withholding' => [
        'requested' => [
            'title'   => 'มีคำขอหักภาษี ณ ที่จ่ายรอตรวจสอบ',
            'message' => '{business} ขอหักภาษี ณ ที่จ่ายตาม {rule} กรุณาตรวจสอบคำขอ',
        ],
        'approved' => [
            'title'   => 'อนุมัติการหักภาษี ณ ที่จ่ายแล้ว',
            'message' => '{business} สามารถหักภาษี ณ ที่จ่ายตาม {rule} ได้แล้ว โดยจะคำนวณในใบแจ้งหนี้ตั้งแต่นี้ไป',
        ],
        'rejected' => [
            'title'   => 'ไม่อนุมัติการหักภาษี ณ ที่จ่าย',
            'message' => 'คำขอหักภาษี ณ ที่จ่ายของ {business} ไม่ได้รับการอนุมัติ หากมีข้อสงสัยกรุณาติดต่อเรา',
        ],
    ],

    'certificate' => [
        'submitted' => [
            'title'   => 'มีหนังสือรับรองรอตรวจสอบ: {invoice_no}',
            'message' => '{business} ส่งหนังสือรับรองการหักภาษี ณ ที่จ่าย {amount} สำหรับใบแจ้งหนี้ {invoice_no} กรุณาตรวจสอบ',
        ],
        'accepted' => [
            'title'   => 'รับหนังสือรับรองแล้ว',
            'message' => 'หนังสือรับรองการหักภาษี ณ ที่จ่ายสำหรับใบแจ้งหนี้ {invoice_no} ได้รับการยอมรับแล้ว ขอบคุณ',
        ],
        'rejected' => [
            'title'   => 'หนังสือรับรองถูกปฏิเสธ',
            'message' => 'หนังสือรับรองการหักภาษี ณ ที่จ่ายสำหรับใบแจ้งหนี้ {invoice_no} ถูกปฏิเสธ: {reason} กรุณาส่งใหม่อีกครั้ง',
        ],
    ],

    'invitation' => [
        'accepted' => [
            'title'   => '{name} เข้าร่วมแล้ว',
            'message' => '{name} ตอบรับคำเชิญและเข้าร่วมในฐานะ{role}',
        ],
    ],

    'review' => [
        'submitted' => [
            'title'   => 'รีวิวใหม่: {artifact}',
            'message' => '{customer} ให้ {artifact} {rating} ดาว กรุณาอนุมัติหรือซ่อนรีวิว',
        ],
    ],

    // order.payment_failed with no reason recorded
    'no_reason' => 'ไม่ได้ระบุเหตุผล',

    // a person's role in a business, for invitation.accepted
    'client_role' => [
        'O' => 'เจ้าของ',
        'M' => 'ผู้จัดการ',
        'E' => 'พนักงาน',
    ],

    // a booking's payment, for the people serving it
    'payment_state' => [
        'paid'     => 'ชำระแล้ว',
        'at_store' => 'ชำระที่ร้าน',
        'awaiting' => 'ยังไม่ได้ชำระ อาจถูกยกเลิกหรือลูกค้าไม่มาได้',
    ],

    'moderation' => [
        'flagged' => [
            'title'   => 'Otternova ได้ทำเครื่องหมาย {subject}',
            'message' => 'เหตุผล: {reason} {message} กรุณาแก้ไขและบันทึก แล้ว Otternova จะตรวจสอบอีกครั้ง',
        ],
        'blocked' => [
            'title'   => '{subject} ถูกระงับ',
            'message' => 'Otternova ได้นำ {subject} ออกจาก OtterNovaMart เหตุผล: {reason} {message} กรุณาแก้ไขและบันทึก จะกลับมาขายได้เมื่อ Otternova ยกเลิกการระงับ',
        ],
        'unblocked' => [
            'title'   => '{subject} ถูกยกเลิกการระงับแล้ว',
            'message' => 'Otternova ได้ยกเลิกการระงับ {subject} แล้ว กลับเป็นเหมือนก่อนถูกระงับ {message}',
        ],
        'removed' => [
            'title'   => '{subject} ถูกนำออกแล้ว',
            'message' => 'Otternova ได้นำ {subject} ออกอย่างถาวร เหตุผล: {reason} {message} คำสั่งซื้อที่มีอยู่ยังคงได้รับการดำเนินการ',
        ],
    ],

    // why Otternova flagged, blocked or removed something (ModerationEventModel::REASONS), for moderation.*
    'moderation_reason' => [
        'PROHIBITED'            => 'สินค้าต้องห้ามหรือถูกจำกัด',
        'MISLEADING'            => 'ข้อมูลทำให้เข้าใจผิดหรือไม่ถูกต้อง',
        'INTELLECTUAL_PROPERTY' => 'ปัญหาทรัพย์สินทางปัญญา',
        'AUDIENCE'              => 'ไม่เหมาะกับกลุ่มผู้ชม',
        'BUSINESS_TYPE'         => 'ไม่ตรงกับประเภทธุรกิจ',
        'PRICING'               => 'ปัญหาด้านราคา',
        'OTHER'                 => 'อื่น ๆ',
    ],

    'report' => [
        'submitted' => [
            'title'   => 'รายงาน: {subject}',
            'message' => 'ผู้เยี่ยมชมรายงาน {subject} ของ {business}: {reason} กรุณาตรวจสอบ',
        ],
    ],

    // what a mart visitor reported (ModerationReportModel::REASONS), for report.submitted
    'report_reason' => [
        'PROHIBITED'            => 'สินค้าหรือบริการต้องห้ามหรือผิดกฎหมาย',
        'SCAM'                  => 'หลอกลวงหรือฉ้อโกง',
        'MISLEADING'            => 'ข้อมูลทำให้เข้าใจผิด',
        'INTELLECTUAL_PROPERTY' => 'ละเมิดลิขสิทธิ์หรือเครื่องหมายการค้า',
        'OFFENSIVE'             => 'เนื้อหาไม่เหมาะสมหรือสร้างความเกลียดชัง',
        'CHILD_SAFETY'          => 'ความปลอดภัยของเด็ก',
        'OTHER'                 => 'อื่น ๆ',
    ],

    'invalidRequest' => 'ไม่สามารถอ่านคำขอได้ กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง',
    'invalidStatus'  => 'กรุณาเลือก ยังไม่อ่าน หรือ อ่านแล้ว',
    'notFound'       => 'ไม่พบการแจ้งเตือนนี้',
    'loadFailed'     => 'ไม่สามารถโหลดการแจ้งเตือนได้ กรุณาลองอีกครั้ง',
    'saveFailed'     => 'ไม่สามารถอัปเดตการแจ้งเตือนได้ กรุณาลองอีกครั้ง',
    'allRead'        => 'ทำเครื่องหมายว่าอ่านแล้วทั้งหมด',
    'deleted'        => 'ลบการแจ้งเตือนแล้ว',
    'readDeleted'    => 'ลบการแจ้งเตือนที่อ่านแล้ว {0} รายการ',
];
