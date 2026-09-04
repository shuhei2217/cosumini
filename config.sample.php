<?php
/**
 * ▼ 使い方
 *  1. このファイルを「config.php」という名前でコピー
 *  2. 下の値を自分のものに書き換える
 *  3. FTP等で サーバーの public_html（index.html と同じ場所）に config.php をアップロード
 *
 *  ※ config.php は GitHub には保存されません（.gitignore 済み）。
 *  ※ 自動デプロイでも config.php は上書き・削除されません。
 */

return array(

    // LINEチャネルアクセストークン（長期）
    //   取得：LINE Developers コンソール → 対象チャネル → 「Messaging API」タブ
    //         → 「チャネルアクセストークン（長期）」を発行してコピー
    'line_token' => 'ここにチャネルアクセストークンを貼り付け',

    // 通知を受け取る相手の userId（U から始まる33文字）
    //   取得：LINE Developers コンソール → 対象チャネル → 「Basic settings」タブ
    //         → 一番下の「Your user ID」
    //   ※ その userId のLINEアカウントで、公式アカウントを「友だち追加」しておくこと
    'line_to'    => 'ここにあなたの userId（U...）を貼り付け',

    // ▼ ここから下のアクセス解析の設定は「書かなくてもOK」です ▼
    //
    //   ・パスワードは https://（サイトURL）/stats.php に初回アクセスしたとき、
    //     画面上で決められます（FTP不要・おすすめ）。
    //   ・GitHub の Secrets に STATS_PASS を登録しておく方法もあります。
    //   ・下の行のコメント（//）を外して直接書くこともできます。その場合は
    //     この config.php の設定が最優先されます。
    //
    // 'stats_pass' => '好きなパスワード',
    //
    //   訪問者を数えるときの内部ソルトも自動生成されるため設定不要です。
    //   （固定したい場合のみ下の行のコメントを外してください）
    // 'count_salt' => '適当な長い文字列',

    // 予備のメール通知（不要なら '' のまま）
    'mail_to'    => '',
    'mail_from'  => 'no-reply@premurosa.biz',
);
