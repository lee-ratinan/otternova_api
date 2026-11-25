<?php

// The Terms of Service and Privacy Policy versions; see App\Controllers\Legal and App\Controllers\LegalAdmin
return [
    'documentNotFound'    => 'この文書が見つかりません。',
    'loadFailed'          => '文書を読み込めませんでした。もう一度お試しください。',
    'versionNotFound'     => 'このバージョンが見つかりません。',
    'invalidRequest'      => 'リクエストが無効です。',
    'invalidFields'       => '強調表示された項目を確認してください。',
    'invalidAudience'     => 'クライアントアプリかマーケットプレイスを選択してください。',
    'invalidType'         => '利用規約またはプライバシーポリシーを選択してください。',
    'invalidLabel'        => '英数字で始まる1〜32文字の英数字、ドット、ハイフン、アンダースコアを使用してください（例: 2026-12-01）。',
    'invalidTime'         => '有効な日時を入力してください。',
    'timeInPast'          => '新しいバージョンを過去の日時から有効にすることはできません。',
    'timeNotAfterLatest'  => '新しいバージョンは最後に設定されたバージョン（{0} UTC）より後に有効にする必要があります。',
    'invalidReacceptance' => '全員にこのバージョンへの再同意を求めるかどうかを選択してください。',
    'labelTaken'          => 'この文書にはすでにこのラベルのバージョンがあります。',
    'unknownLocale'       => 'この言語はサポートされていません。',
    'bodyRequired'        => '文書の本文を入力してください。',
    'bodyTooLong'         => '本文は最大{0}バイトです。',
    'summaryTooLong'      => '変更の概要は最大{0}文字です。',
    'englishRequired'     => 'すべてのバージョンに英語（en-US）の本文が必要です。',
    'saveFailed'          => 'バージョンを保存できませんでした。もう一度お試しください。',
    'published'           => 'バージョンを公開しました。',
    'cannotCancel'        => '取り消せるのはまだ有効になっていないバージョンのみです。',
    'cancelled'           => 'バージョンを取り消しました。',
];
