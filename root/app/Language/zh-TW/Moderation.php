<?php

/** The Admin app's review and moderation (App\Controllers\ModerationAdmin) */
return [
    'invalidRequest'   => '無法讀取請求。請重新載入頁面後再試。',
    'invalidFields'    => '請檢查標記的欄位。',
    'invalidSubject'   => '請選擇商家、商品或服務，或規格。',
    'invalidAction'    => '無法對其執行此操作。',
    'invalidReason'    => '請從清單中選擇原因。',
    'reasonRequired'   => '請選擇原因。',
    'messageRequired'  => '請告訴商家需要修改什麼。',
    'tooLong'          => '請不要超過 {0} 個字元。',
    'businessNotFound' => '找不到商家。',
    'artifactNotFound' => '找不到商品或服務。',
    'variantNotFound'  => '找不到規格。',
    'removedAlready'   => '已永久移除，無法再執行任何操作。',
    'blockedAlready'   => '已經被封鎖。',
    'notBlocked'       => '未被封鎖。',
    'unblockFirst'     => '目前被封鎖：請先解除封鎖再核准。',
    'loadFailed'       => '無法載入審核紀錄。請再試一次。',
    'saveFailed'       => '無法儲存。請再試一次。',
    'done'               => [
        'AP' => '已核准。',
        'FL' => '已標記，並已請商家修改。',
        'BL' => '已封鎖，已從市集下架。',
        'UB' => '已解除封鎖並核准。',
        'RM' => '已永久移除。',
    ],
];
