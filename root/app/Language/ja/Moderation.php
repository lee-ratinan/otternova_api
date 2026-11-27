<?php

/** The Admin app's review and moderation (App\Controllers\ModerationAdmin) */
return [
    'invalidRequest'   => 'リクエストを読み取れませんでした。ページを再読み込みして、もう一度お試しください。',
    'invalidFields'    => 'マークされた項目をご確認ください。',
    'invalidSubject'   => 'ビジネス、商品またはサービス、バリエーションのいずれかを選んでください。',
    'invalidAction'    => 'この操作は実行できません。',
    'invalidReason'    => '理由を一覧から選んでください。',
    'reasonRequired'   => '理由を選んでください。',
    'messageRequired'  => 'ビジネスに修正してほしい点を入力してください。',
    'tooLong'          => '{0} 文字以内で入力してください。',
    'businessNotFound' => 'ビジネスが見つかりません。',
    'artifactNotFound' => '商品またはサービスが見つかりません。',
    'variantNotFound'  => 'バリエーションが見つかりません。',
    'removedAlready'   => '完全に削除済みのため、これ以上操作できません。',
    'blockedAlready'   => 'すでにブロックされています。',
    'notBlocked'       => 'ブロックされていません。',
    'unblockFirst'     => 'ブロック中です。承認するにはブロックを解除してください。',
    'loadFailed'       => '審査履歴を読み込めませんでした。もう一度お試しください。',
    'saveFailed'       => '保存できませんでした。もう一度お試しください。',
    'done'               => [
        'AP' => '承認しました。',
        'FL' => 'フラグを付け、ビジネスに修正を依頼しました。',
        'BL' => 'ブロックし、マーケットプレイスから外しました。',
        'UB' => 'ブロックを解除して承認しました。',
        'RM' => '完全に削除しました。',
    ],
];
