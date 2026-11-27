<?php

/** The Admin app's review and moderation (App\Controllers\ModerationAdmin) */
return [
    'invalidRequest'   => '无法读取请求。请重新加载页面后再试。',
    'invalidFields'    => '请检查标记的栏位。',
    'invalidSubject'   => '请选择企业、商品或服务，或规格。',
    'invalidAction'    => '无法对其执行此操作。',
    'invalidReason'    => '请从列表中选择原因。',
    'reasonRequired'   => '请选择原因。',
    'messageRequired'  => '请告诉企业需要修改什么。',
    'tooLong'          => '请不要超过 {0} 个字符。',
    'businessNotFound' => '找不到企业。',
    'artifactNotFound' => '找不到商品或服务。',
    'variantNotFound'  => '找不到规格。',
    'removedAlready'   => '已永久移除，无法再执行任何操作。',
    'blockedAlready'   => '已经被屏蔽。',
    'notBlocked'       => '未被屏蔽。',
    'unblockFirst'     => '目前被屏蔽：请先解除屏蔽再批准。',
    'loadFailed'       => '无法加载审核记录。请再试一次。',
    'saveFailed'       => '无法保存。请再试一次。',
    'done'               => [
        'AP' => '已批准。',
        'FL' => '已标记，并已请企业修改。',
        'BL' => '已屏蔽，已从市集下架。',
        'UB' => '已解除屏蔽并批准。',
        'RM' => '已永久移除。',
    ],
];
