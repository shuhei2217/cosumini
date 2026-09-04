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

    // アクセス解析ページ（stats.php）のパスワード
    //   https://（サイトURL）/stats.php を開くときに入力します。
    //   空のままだと解析ページは開けません。
    'stats_pass' => 'ここに好きなパスワードを入力',

    // 訪問者を数えるときの内部ソルト（推測されにくい適当な文字列に変更）
    //   ※ IPアドレスはこの値と一緒にハッシュ化され、元に戻せない形で扱われます。
    'count_salt' => 'ここに適当な長い文字列（例：kokubu-cosmini-2026-xyz）',

    // 予備のメール通知（不要なら '' のまま）
    'mail_to'    => '',
    'mail_from'  => 'no-reply@premurosa.biz',
);
