<?php
return [
    'template'            => [
        'closing_message'     => 'หากมีคำถาม กรุณาติดต่อทีมสนับสนุนของเรา',
        'best_regards'        => 'ขอแสดงความนับถือ',
        'team_name'           => 'ทีมงาน OtterNova',
        'company_name'        => 'OtterNova',
        'company_address'     => '##/## ถนนสรงประภา แขวงดอนเมือง เขตดอนเมือง กรุงเทพมหานคร 10210 ประเทศไทย',
        'contact_support'     => 'ติดต่อฝ่ายสนับสนุน',
        'privacy_policy'      => 'นโยบายความเป็นส่วนตัว',
        'all_rights_reserved' => 'สงวนลิขสิทธิ์',
    ],
    'login_otp'           => [
        'subject' => 'รหัสเข้าสู่ระบบ OtterNova ของคุณ',
        'body'    => "สวัสดีคุณ {full_name}\n\nรหัสเข้าสู่ระบบของคุณคือ {otp} รหัสนี้จะหมดอายุภายใน {minutes} นาที\n\nหากคุณไม่ได้พยายามเข้าสู่ระบบ กรุณาเปลี่ยนรหัสผ่านของคุณ",
    ],
    'account_activation'  => [
        'subject' => 'เปิดใช้งานบัญชี OtterNova ของคุณ',
        'body'    => "สวัสดีคุณ {full_name}\n\nขอบคุณที่สมัครใช้งาน OtterNova กรุณาเปิดลิงก์นี้เพื่อเปิดใช้งานบัญชีของคุณ:\n\n{link}\n\nลิงก์จะหมดอายุภายใน {hours} ชั่วโมง หากคุณไม่ได้สมัคร สามารถเพิกเฉยต่ออีเมลนี้ได้",
    ],
    'account_exists'      => [
        'subject' => 'บัญชี OtterNova ของคุณ',
        'body'    => "สวัสดีคุณ {full_name}\n\nมีผู้พยายามสมัคร OtterNova ด้วยอีเมลนี้ แต่อีเมลนี้มีบัญชีอยู่แล้ว คุณสามารถเข้าสู่ระบบได้ที่:\n\n{link}\n\nหากคุณลืมรหัสผ่าน ให้ใช้ \"ลืมรหัสผ่าน\" ในหน้าเข้าสู่ระบบ หากไม่ใช่คุณ สามารถเพิกเฉยต่ออีเมลนี้ได้",
    ],
    'business_invitation' => [
        'subject' => '{inviter} เชิญคุณร่วมงานกับ {business_name} บน OtterNova',
        'body'    => "สวัสดี\n\n{inviter} เชิญคุณร่วมงานกับ {business_name} บน OtterNova ในบทบาท {role} กรุณาเปิดลิงก์นี้เพื่อสร้างบัญชีด้วยอีเมลนี้:\n\n{link}\n\nลิงก์จะหมดอายุภายใน {days} วัน หากคุณไม่ได้คาดว่าจะได้รับคำเชิญนี้ สามารถเพิกเฉยต่ออีเมลนี้ได้",
    ],
    'password_reset'      => [
        'subject' => 'ตั้งรหัสผ่าน OtterNova ใหม่',
        'body'    => "สวัสดีคุณ {full_name}\n\nเราได้รับคำขอตั้งรหัสผ่านใหม่สำหรับบัญชี OtterNova ของคุณ กรุณาเปิดลิงก์นี้เพื่อตั้งรหัสผ่านใหม่:\n\n{link}\n\nลิงก์จะหมดอายุภายใน {minutes} นาทีและใช้ได้เพียงครั้งเดียว หากคุณไม่ได้ขอ สามารถเพิกเฉยต่ออีเมลนี้ได้ รหัสผ่านของคุณจะยังคงเหมือนเดิม",
    ],
    'order_cancelled'    => [
        'subject' => 'คำสั่งซื้อ {order_number} กับ {business_name} ถูกยกเลิกแล้ว',
        'body'    => "สวัสดีคุณ {first_name}\n\nคำสั่งซื้อ {order_number} กับ {business_name} ถูกยกเลิกแล้ว คำสั่งซื้อนี้ยังไม่ได้ชำระเงิน จึงไม่มียอดที่ต้องคืน\n\nคุณสามารถดูคำสั่งซื้อได้ที่:\n\n{link}\n\nหากมีข้อสงสัย กรุณาติดต่อ {business_name}",
    ],
    'order_cancelled_paid' => [
        'subject' => 'คำสั่งซื้อ {order_number} กับ {business_name} ถูกยกเลิกแล้ว',
        'body'    => "สวัสดีคุณ {first_name}\n\nคำสั่งซื้อ {order_number} กับ {business_name} ถูกยกเลิกแล้ว คุณได้ชำระเงินไปแล้ว {amount} ซึ่ง {business_name} จะคืนเงินให้คุณ และอาจติดต่อคุณเกี่ยวกับวิธีการคืนเงิน\n\nคุณสามารถดูคำสั่งซื้อได้ที่:\n\n{link}\n\nหากมีข้อสงสัย กรุณาติดต่อ {business_name}",
    ],
    'order_rescheduled'  => [
        'subject' => 'การจองของคุณกับ {business_name} ถูกเลื่อนเป็น {to}',
        'body'    => "สวัสดีคุณ {first_name}\n\nการจอง {service} กับ {business_name} (คำสั่งซื้อ {order_number}) ถูกเลื่อนจาก {from} เป็น {to}\n\nคุณสามารถดูคำสั่งซื้อได้ที่:\n\n{link}\n\nหากเวลานี้ไม่สะดวก กรุณาติดต่อ {business_name}",
    ],
    'order_placed'       => [
        'subject'        => 'คำสั่งซื้อ {order_number} ของคุณกับ {business_name}',
        'body'           => "สวัสดีคุณ {first_name}\n\nขอบคุณที่สั่งซื้อกับ {business_name} หมายเลขคำสั่งซื้อของคุณคือ {order_number}\n\n{items}\n\nยอดรวม: {total}\n\nยังไม่ได้ชำระเงิน ช่องทางชำระเงิน: {method}\n{how}\n\nคุณสามารถดูคำสั่งซื้อ ชำระเงิน และแจ้งเมื่อชำระแล้วได้ที่:\n\n{link}\n\nหากยังไม่ชำระเงิน คำสั่งซื้อของคุณอาจถูกยกเลิก",
        'promptpay'      => 'สแกน QR PromptPay ในหน้าคำสั่งซื้อ ซึ่งมียอดเงินอยู่แล้ว',
        'shipping'       => 'ค่าจัดส่ง: {0}',
        'no-instruction' => '{business_name} จะแจ้งวิธีชำระเงินให้คุณทราบ',
    ],
    'order_placed_paid'  => [
        'subject' => 'คำสั่งซื้อ {order_number} ของคุณกับ {business_name}',
        'body'    => "สวัสดีคุณ {first_name}\n\nขอบคุณที่สั่งซื้อกับ {business_name} หมายเลขคำสั่งซื้อของคุณคือ {order_number}\n\n{items}\n\nยอดรวม: {total} ชำระแล้วผ่าน {method}\n\nคุณสามารถดูคำสั่งซื้อได้ที่:\n\n{link}",
    ],
];
