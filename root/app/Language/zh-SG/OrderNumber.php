<?php

// App\Controllers\OrderNumbers: how the order numbers of a business look
return [
    'invalidRequest'   => '无法读取请求。请重新加载页面后再试。',
    'invalidFields'    => '请更正标示的字段。',
    'loadFailed'       => '无法加载订单号设置。请再试一次。',
    'saveFailed'       => '无法保存。请再试一次。',
    'slugRequired'     => '请选择一家企业。',
    'businessNotFound' => '找不到企业。',
    'managersOnly'     => '只有这家企业的业主和经理才能更改其订单号。',
    'saved'            => '订单号设置已保存。',
    'order_prefix'     => '前缀最多 4 个字母，A 至 Z。',
    'order_date_part'  => '请选择日期部分。',
    'order_reset'      => '请选择编号何时重新开始。',
    'order_digits'     => '流水号必须为 3 至 6 位数。',
    'resetTooOften'    => '编号重新开始的频率不能高于日期部分所显示的：每日重置需要完整日期，每月重置需要月份。',
];
