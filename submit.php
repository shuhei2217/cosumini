<?php
/**
 * 予約注文フォームの受信 → LINE通知（Messaging API push）＋ 予備のメール通知
 * トークン等は同じフォルダの config.php から読み込みます（config.php はGitHubには入りません）。
 */

header('Content-Type: application/json; charset=utf-8');
mb_internal_encoding('UTF-8');
mb_language('uni');

function out($ok, $extra = array())
{
    echo json_encode(array_merge(array('ok' => $ok), $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    out(false, array('error' => 'method'));
}

$cfgPath = __DIR__ . '/config.php';
if (!is_file($cfgPath)) {
    http_response_code(500);
    out(false, array('error' => 'server_not_configured'));
}
$config = require $cfgPath;

// --- スパム対策 -----------------------------------------------------------
if (!empty($_POST['website'])) {
    out(true); // ハニーポット：ボットには成功を返して破棄
}
$ts = isset($_POST['ts']) ? (int) $_POST['ts'] : 0;
if ($ts > 0 && (round(microtime(true) * 1000) - $ts) < 2500) {
    out(true); // 送信が速すぎる＝ボット
}

// --- 入力取得＆サニタイズ -------------------------------------------------
function field($key, $max)
{
    $s = isset($_POST[$key]) ? trim((string) $_POST[$key]) : '';
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    return mb_substr($s, 0, $max);
}

$category     = field('category', 60);
$product      = field('product', 120);
$product_free = field('product_free', 120);
$qty          = field('qty', 30);
$name         = field('name', 80);
$tel          = field('tel', 30);
$pickup       = field('pickup', 40);
$note         = field('note', 1000);

if ($product === 'その他（自由記入）' && $product_free !== '') {
    $product = $product_free;
}

// --- バリデーション -----------------------------------------------------
$errors = array();
if ($name === '') $errors[] = 'name';
if (strlen(preg_replace('/[^0-9]/', '', $tel)) < 9) $errors[] = 'tel';
if ($category === '') $errors[] = 'category';
if ($product === '') $errors[] = 'product';
if ($qty === '') $errors[] = 'qty';
if ($errors) {
    http_response_code(422);
    out(false, array('error' => 'validation', 'fields' => $errors));
}

// --- 簡易レート制限（同一IP：10分に5件まで）---------------------------
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rlFile = sys_get_temp_dir() . '/cosmini_rl_' . md5($ip) . '.json';
$now = time();
$hits = is_file($rlFile) ? (json_decode((string) file_get_contents($rlFile), true) ?: array()) : array();
$hits = array_values(array_filter($hits, function ($t) use ($now) { return $t > $now - 600; }));
if (count($hits) >= 5) {
    http_response_code(429);
    out(false, array('error' => 'rate'));
}
$hits[] = $now;
@file_put_contents($rlFile, json_encode($hits));

// --- 通知本文 --------------------------------------------------------
$rows = array(
    '🛒 予約注文が入りました',
    '━━━━━━━━━━',
    'カテゴリー：' . $category,
    '商品　：' . $product,
    '個数　：' . $qty,
    'お名前：' . $name,
    '電話　：' . $tel,
    '受取希望：' . ($pickup !== '' ? $pickup : '相談・未選択'),
);
if ($note !== '') {
    $rows[] = '備考　：' . $note;
}
$rows[] = '━━━━━━━━━━';
$rows[] = '受信：' . date('Y-m-d H:i');
$text = implode("\n", $rows);

// --- バックアップ：サーバーにログ保存 --------------------------------
@file_put_contents(__DIR__ . '/orders.log', $text . "\n\n", FILE_APPEND | LOCK_EX);

// --- LINE通知（Messaging API push）----------------------------------
$lineOk = false;
$lineDetail = '';
if (!empty($config['line_token']) && !empty($config['line_to'])) {
    $payload = json_encode(array(
        'to' => $config['line_to'],
        'messages' => array(array('type' => 'text', 'text' => $text)),
    ), JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.line.me/v2/bot/message/push');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $config['line_token'],
        ),
        CURLOPT_POSTFIELDS => $payload,
    ));
    $resp = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    $lineOk = ($httpCode === 200);
    if (!$lineOk) {
        $lineDetail = $curlErr !== '' ? $curlErr : ('HTTP ' . $httpCode . ' ' . $resp);
        @file_put_contents(__DIR__ . '/orders.log', '  [LINE送信失敗] ' . $lineDetail . "\n\n", FILE_APPEND | LOCK_EX);
    }
}

// --- 予備のメール通知（config で mail_to を設定した場合のみ）---------
$mailOk = false;
if (!empty($config['mail_to'])) {
    $subject = '【予約注文】' . $product . ' ×' . $qty . '（' . $name . '様）';
    $headers = '';
    if (!empty($config['mail_from'])) {
        $headers = 'From: ' . $config['mail_from'];
    }
    $mailOk = @mb_send_mail($config['mail_to'], $subject, $text, $headers);
}

if ($lineOk || $mailOk) {
    out(true);
}

http_response_code(502);
out(false, array('error' => 'notify_failed', 'detail' => $lineDetail));
