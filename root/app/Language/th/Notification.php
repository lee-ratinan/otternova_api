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
    ],

    // a booking's payment, for the people serving it
    'payment_state' => [
        'paid'     => 'ชำระแล้ว',
        'at_store' => 'ชำระที่ร้าน',
        'awaiting' => 'ยังไม่ได้ชำระ อาจถูกยกเลิกหรือลูกค้าไม่มาได้',
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
