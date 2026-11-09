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

    'invalidRequest' => 'ไม่สามารถอ่านคำขอได้ กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง',
    'invalidStatus'  => 'กรุณาเลือก ยังไม่อ่าน หรือ อ่านแล้ว',
    'notFound'       => 'ไม่พบการแจ้งเตือนนี้',
    'loadFailed'     => 'ไม่สามารถโหลดการแจ้งเตือนได้ กรุณาลองอีกครั้ง',
    'saveFailed'     => 'ไม่สามารถอัปเดตการแจ้งเตือนได้ กรุณาลองอีกครั้ง',
    'allRead'        => 'ทำเครื่องหมายว่าอ่านแล้วทั้งหมด',
    'deleted'        => 'ลบการแจ้งเตือนแล้ว',
    'readDeleted'    => 'ลบการแจ้งเตือนที่อ่านแล้ว {0} รายการ',
];
