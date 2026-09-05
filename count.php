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
// ボットは数えませんが、「届いたが数えなかった」ことが分かるよう記録は残します
$skipReason = '';
if ($ua === '') {
    $skipReason = 'ua_empty';
} elseif (preg_match($botRe, $ua)) {
    $skipReason = 'bot';
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
$ver = param('v', 10);        // 計測タグのバージョン（どの版のページから届いたか）
$isTest = (param('test', 3) === '1');   // 動作確認用の送信（アクセス数には数えない）

// 流入元は「ホスト名」だけ保存（自サイト内リンクは除外）
$refHost = '';
if ($ref !== '') {
    $h = parse_url($ref, PHP_URL_HOST);
    $selfHost = isset($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST']) : '';
    if ($h && strcasecmp($h, $selfHost) !== 0) {
        $refHost = mb_substr(preg_replace('/^www\./i', '', strtolower($h)), 0, 80);
    }
}

// --- 保存先の準備 -----------------------------------------------------
if (!is_dir($DATA_DIR)) {
    @mkdir($DATA_DIR, 0755, true);
}
if (is_dir($DATA_DIR) && !is_writable($DATA_DIR)) {
    // FTPで作られたフォルダにPHPが書き込めない場合があるため、権限の修正を試みる
    @chmod($DATA_DIR, 0777);
    clearstatcache();
}
if (!is_dir($DATA_DIR) || !is_writable($DATA_DIR)) {
    http_response_code(500);
    respond(array(
        'ok'    => false,
        'error' => 'data_dir_not_writable',
        'hint'  => 'サーバーの data フォルダに書き込めません。FTPで data フォルダの属性を 777 に変更してください。',
        'dir'   => $DATA_DIR,
    ));
}
// data/ の中身を直接ダウンロードされないように保護
$ht = $DATA_DIR . '/.htaccess';
if (!is_file($ht)) {
    @file_put_contents($ht, "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
}

// --- 訪問者ハッシュ（IP + UA をソルト付きでハッシュ化）----------------
// ソルトは設定不要です。サーバー上で自動生成し data/salt.txt に保存します。
// （config.php に 'count_salt' を書いた場合はそちらを優先）
$salt = '';
$cfgPath = __DIR__ . '/config.php';
if (is_file($cfgPath)) {
    $cfg = @include $cfgPath;
    if (is_array($cfg) && !empty($cfg['count_salt'])) {
        $salt = (string) $cfg['count_salt'];
    }
}
if ($salt === '') {
    $saltFile = $DATA_DIR . '/salt.txt';
    if (is_file($saltFile)) {
        $salt = trim((string) @file_get_contents($saltFile));
    }
    if ($salt === '') {
        if (function_exists('random_bytes')) {
            $salt = bin2hex(random_bytes(16));
        } else {
            $salt = hash('sha256', uniqid('', true) . mt_rand());
        }
        @file_put_contents($saltFile, $salt, LOCK_EX);
        @chmod($saltFile, 0600);
    }
}
$ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
$visitor = substr(hash('sha256', $salt . '|' . $ip . '|' . $ua), 0, 16);

$isMobile = ($sw > 0 && $sw <= 767) || preg_match('/Mobile|Android|iPhone|iPod|Windows Phone/i', $ua);

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
if ($isTest && $skipReason === '') {
    $skipReason = 'test';       // 動作確認用のためアクセス数には加えない
}
$dupe = false;
if (isset($d['visitors'][$today][$visitor]) && ($now - (int) $d['visitors'][$today][$visitor]) < 10) {
    $dupe = true;
    if ($skipReason === '') {
        $skipReason = 'dupe_10sec';
    }
}

// 過去に動作確認用のテスト送信が数えられていた分を、一度だけ差し引く
// （テストは /self-test として記録されているため、本物の訪問には影響しません）
if (isset($d['pages']['/self-test'])) {
    $testHits = (int) $d['pages']['/self-test'];
    unset($d['pages']['/self-test']);
    $d['total'] = max(0, (int) $d['total'] - $testHits);
    if (isset($d['days'][$today]['pv']) && (int) $d['days'][$today]['pv'] >= $testHits) {
        $d['days'][$today]['pv'] = (int) $d['days'][$today]['pv'] - $testHits;
        $d['days'][$today]['uv'] = max(0, (int) $d['days'][$today]['uv'] - 1);
        $d['days'][$today]['mb'] = max(0, (int) $d['days'][$today]['mb'] - $testHits);
    } else {
        // 日付をまたいでいた場合は、差し引ける最新の日から引く
        foreach (array_reverse(array_keys($d['days'])) as $dk) {
            if ((int) $d['days'][$dk]['pv'] >= $testHits) {
                $d['days'][$dk]['pv'] = (int) $d['days'][$dk]['pv'] - $testHits;
                $d['days'][$dk]['uv'] = max(0, (int) $d['days'][$dk]['uv'] - 1);
                $d['days'][$dk]['mb'] = max(0, (int) $d['days'][$dk]['mb'] - $testHits);
                break;
            }
        }
    }
}

// 直近20件の受信記録（動作確認用。IPアドレスは保存しません）
if (!isset($d['recent']) || !is_array($d['recent'])) {
    $d['recent'] = array();
}
$d['recent'][] = array(
    't' => date('m/d H:i:s'),
    'p' => $path,
    'c' => ($skipReason === '') ? 1 : 0,
    'r' => $skipReason,
    'm' => $isMobile ? 1 : 0,
    'u' => mb_substr($ua, 0, 70),
    'v' => $ver,
);
if (count($d['recent']) > 20) {
    $d['recent'] = array_slice($d['recent'], -20);
}
$d['last_req'] = date('Y-m-d H:i:s');

if ($skipReason === '') {
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

if ($skipReason !== 'bot' && $skipReason !== 'ua_empty' && $skipReason !== 'test') {
    $d['visitors'][$today][$visitor] = $now;
}
if ($skipReason === '') {
    $d['updated'] = date('Y-m-d H:i:s');
}

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
    'ok'      => true,
    'counted' => ($skipReason === ''),
    'reason'  => $skipReason,                  // test / bot / ua_empty / dupe_10sec のいずれか
    'note'    => $isTest ? 'テスト送信のためアクセス数には加えていません' : '',
    'total'   => (int) $d['total'],
    'today'   => isset($d['days'][$today]['pv']) ? (int) $d['days'][$today]['pv'] : 0,
));
