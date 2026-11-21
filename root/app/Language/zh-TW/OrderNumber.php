<?php

// App\Controllers\OrderNumbers: how the order numbers of a business look
return [
    'invalidRequest'   => '無法讀取請求。請重新載入頁面後再試。',
    'invalidFields'    => '請修正標示的欄位。',
    'loadFailed'       => '無法載入訂單編號設定。請再試一次。',
    'saveFailed'       => '無法儲存。請再試一次。',
    'slugRequired'     => '請選擇一間商家。',
    'businessNotFound' => '找不到商家。',
    'managersOnly'     => '只有這間商家的負責人與經理才能變更其訂單編號。',
    'saved'            => '訂單編號設定已儲存。',
    'order_prefix'     => '前綴最多 4 個字母，A 到 Z。',
    'order_date_part'  => '請選擇日期部分。',
    'order_reset'      => '請選擇編號何時重新開始。',
    'order_digits'     => '流水號必須為 3 到 6 位數。',
    'resetTooOften'    => '編號重新開始的頻率不能高於日期部分所顯示的：每日重設需要完整日期，每月重設需要月份。',
];
