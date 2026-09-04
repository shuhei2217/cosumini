<?php
/**
 * アクセス計測エンドポイント（自前カウンター）
 * ------------------------------------------------------------------
 * index.html の末尾スクリプトから呼ばれ、ページビューを記録します。
 * 集計結果は data/access.json に保存され、stats.php で閲覧できます。
 *
 * ・個人情報は保存しません（IPはソルト付きハッシュ化し、重複判定にのみ使用）
 * ・外部サービス不要。サーバー内だけで完結します。
 * ・data/ ディレクトリは自動生成され、FTP自動デプロイでも消えません。
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Robots-Tag: noindex');

$DATA_DIR  = __DIR__ . '/data';
$DATA_FILE = $DATA_DIR . '/access.json';
$KEEP_DAYS = 400;   // 日別データの保持日数
$KEEP_VIS  = 7;     // 訪問者ハッシュの保持日数（UU判定用）
$TOP_KEEP  = 300;   // ページ/流入元の保持件数上限

function respond($arr)
{
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- ボット・クローラーは数えない -------------------------------------
$ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
$botRe = '/bot|crawl|spider|slurp|mediapartners|facebookexternalhit|headless|phantom|monitor|uptime|curl|wget|python|java\/|go-http|libwww|okhttp|ahrefs|semrush|mj12|dotbot|petal|yandex|baidu|applebot|gptbot|claudebot|ccbot|bytespider|preview/i';
if ($ua === '' || preg_match($botRe, $ua)) {
    respond(array('ok' => true, 'skipped' => 'bot'));
}

// --- 入力（GET/POST どちらでも受け付ける）-----------------------------
function param($key, $max)
{
    $v = '';
    if (isset($_POST[$key])) {
        $v = (string) $_POST[$key];
    } elseif (isset($_GET[$key])) {
        $v = (string) $_GET[$key];
    }
    $v = trim($v);
    $v = preg_replace('/[\x00-\x1F\x7F]/u', '', $v);
    return mb_substr($v, 0, $max);
}

$path = param('p', 200);
if ($path === '' || $path[0] !== '/') {
    $path = '/';
}
// 「#～」を除去し、「/index.html」と「/」は同じページとして集計する
$path = preg_replace('/#.*$/', '', $path);
$path = preg_replace('#/index\.html?$#i', '/', $path);
if ($path === '') {
    $path = '/';
}
$ref = param('r', 300);
$sw  = (int) param('w', 6);   // 画面幅（モバイル判定用）

// 流入元は「ホスト名」だけ保存（自サイト内リンクは除外）
$refHost = '';
if ($ref !== '') {
    $h = parse_url($ref, PHP_URL_HOST);
    $selfHost = isset($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST']) : '';
    if ($h && strcasecmp($h, $selfHost) !== 0) {
        $refHost = mb_substr(preg_replace('/^www\./i', '', strtolower($h)), 0, 80);
    }
}

// --- 訪問者ハッシュ（IP + UA をソルト付きでハッシュ化）----------------
$salt = 'cosmini';
$cfgPath = __DIR__ . '/config.php';
if (is_file($cfgPath)) {
    $cfg = @include $cfgPath;
    if (is_array($cfg) && !empty($cfg['count_salt'])) {
        $salt = (string) $cfg['count_salt'];
    }
}
$ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
$visitor = substr(hash('sha256', $salt . '|' . $ip . '|' . $ua), 0, 16);

$isMobile = ($sw > 0 && $sw <= 767) || preg_match('/Mobile|Android|iPhone|iPod|Windows Phone/i', $ua);

// --- 保存先の準備 -----------------------------------------------------
if (!is_dir($DATA_DIR)) {
    @mkdir($DATA_DIR, 0755, true);
}
if (!is_dir($DATA_DIR) || !is_writable($DATA_DIR)) {
    http_response_code(500);
    respond(array('ok' => false, 'error' => 'data_dir_not_writable'));
}
// data/ の中身を直接ダウンロードされないように保護
$ht = $DATA_DIR . '/.htaccess';
if (!is_file($ht)) {
    @file_put_contents($ht, "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
}

$fp = @fopen($DATA_FILE, 'c+');
if (!$fp) {
    http_response_code(500);
    respond(array('ok' => false, 'error' => 'open_failed'));
}
@flock($fp, LOCK_EX);

$raw = stream_get_contents($fp);
$d = json_decode((string) $raw, true);
if (!is_array($d)) {
    $d = array();
}
$d += array('total' => 0, 'days' => array(), 'pages' => array(), 'refs' => array(), 'visitors' => array(), 'since' => date('Y-m-d'));

$today = date('Y-m-d');
$hour  = (int) date('G');
$now   = time();

// 同じ訪問者の連打（10秒以内の同一ページ）は二重カウントしない
$dupe = false;
if (isset($d['visitors'][$today][$visitor]) && ($now - (int) $d['visitors'][$today][$visitor]) < 10) {
    $dupe = true;
}

if (!$dupe) {
    if (!isset($d['days'][$today])) {
        $d['days'][$today] = array('pv' => 0, 'uv' => 0, 'mb' => 0, 'hours' => array_fill(0, 24, 0));
    }
    if (!isset($d['days'][$today]['hours']) || !is_array($d['days'][$today]['hours']) || count($d['days'][$today]['hours']) !== 24) {
        $d['days'][$today]['hours'] = array_fill(0, 24, 0);
    }

    $isNewVisitorToday = !isset($d['visitors'][$today][$visitor]);

    $d['total']++;
    $d['days'][$today]['pv']++;
    $d['days'][$today]['hours'][$hour] = (int) $d['days'][$today]['hours'][$hour] + 1;
    if ($isNewVisitorToday) {
        $d['days'][$today]['uv']++;
    }
    if ($isMobile) {
        $d['days'][$today]['mb']++;
    }

    $d['pages'][$path] = (isset($d['pages'][$path]) ? (int) $d['pages'][$path] : 0) + 1;
    if ($refHost !== '') {
        $d['refs'][$refHost] = (isset($d['refs'][$refHost]) ? (int) $d['refs'][$refHost] : 0) + 1;
    }
}

$d['visitors'][$today][$visitor] = $now;
$d['updated'] = date('Y-m-d H:i:s');

// --- 古いデータの整理 -------------------------------------------------
$limitDay = date('Y-m-d', strtotime('-' . $KEEP_DAYS . ' days'));
foreach (array_keys($d['days']) as $k) {
    if ($k < $limitDay) {
        unset($d['days'][$k]);
    }
}
$limitVis = date('Y-m-d', strtotime('-' . $KEEP_VIS . ' days'));
foreach (array_keys($d['visitors']) as $k) {
    if ($k < $limitVis) {
        unset($d['visitors'][$k]);
    }
}
foreach (array('pages', 'refs') as $key) {
    if (count($d[$key]) > $TOP_KEEP) {
        arsort($d[$key]);
        $d[$key] = array_slice($d[$key], 0, $TOP_KEEP, true);
    }
}
ksort($d['days']);

ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
fflush($fp);
@flock($fp, LOCK_UN);
fclose($fp);

respond(array(
    'ok'    => true,
    'total' => (int) $d['total'],
    'today' => (int) $d['days'][$today]['pv'],
));
