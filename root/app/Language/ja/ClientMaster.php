<?php
return [
    'fields' => [
        'auth_id'            => 'アカウントID',
        'legal_first_name'   => '名（法的氏名）',
        'legal_middle_name'  => 'ミドルネーム（法的氏名）',
        'legal_last_name'    => '姓（法的氏名）',
        'display_name'       => '表示名',
        'national_id_type'   => '身分証明書の種類',
        'national_id_number' => '身分証明書の番号',
        'status_message'     => 'ステータスメッセージ',
        'status_emoji'       => 'ステータス絵文字',
        'gender'             => '性別',
        'date_of_birth'      => '生年月日',
        'nationality_code'   => '国籍',
        'preferred_language' => '希望言語',
        'bio'                => '自己紹介',
        'marketing_opt_in'   => 'マーケティング情報の受け取り',
        'internal_notes'     => '社内メモ',
    ],
    'enum'   => [
        'national_id_type' => ['N' => '国民ID', 'P' => 'パスポート'],
        'gender'           => ['M' => '男性', 'F' => '女性', 'NB' => 'ノンバイナリー', 'X' => 'その他', 'N' => '回答しない'],
    ],
];
