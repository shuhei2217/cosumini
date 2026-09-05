<?php
/**
 * アクセス解析ダッシュボード
 * ------------------------------------------------------------------
 * ブラウザで  https://（サイトのURL）/stats.php  を開くと閲覧できます。
 *
 * ▼ パスワードの決め方（次の順で採用されます。どれか1つでOK）
 *   1. ブラウザで初回アクセスしたときに画面上で設定する（FTP不要・おすすめ）
 *      → data/stats-auth.php にハッシュ化して保存されます
 *   2. GitHub の Secrets に STATS_PASS を登録する（自動デプロイ時に反映）
 *   3. サーバー上の config.php に 'stats_pass' => '…' を書く
 *
 * ・?csv=1  … 日別データをCSVでダウンロード
 * ・?logout=1 … ログアウト
 */

mb_internal_encoding('UTF-8');
header('X-Robots-Tag: noindex, nofollow');

$DATA_FILE = __DIR__ . '/data/access.json';
$COOKIE    = 'cosmini_stats';

// --- パスワードの読み込み ---------------------------------------------
$DATA_DIR  = __DIR__ . '/data';
$AUTH_FILE = $DATA_DIR . '/stats-auth.php';

$pass = '';      // 平文で設定された場合（config.php / GitHub Secrets）
$passHash = '';  // 画面上で設定された場合（ハッシュで保存）

// 1) config.php
$cfgPath = __DIR__ . '/config.php';
if (is_file($cfgPath)) {
    $cfg = @include $cfgPath;
    if (is_array($cfg) && !empty($cfg['stats_pass'])) {
        $pass = (string) $cfg['stats_pass'];
    }
}
// 2) data/stats-auth.php（画面設定のハッシュ、または GitHub Secrets 由来の平文）
if ($pass === '' && is_file($AUTH_FILE)) {
    $auth = @include $AUTH_FILE;
    if (is_array($auth)) {
        if (!empty($auth['hash'])) {
            $passHash = (string) $auth['hash'];
        } elseif (!empty($auth['pass_b64'])) {
            $pass = (string) base64_decode((string) $auth['pass_b64']);
        } elseif (!empty($auth['pass'])) {
            $pass = (string) $auth['pass'];
        }
    }
}

$hasPassword = ($pass !== '' || $passHash !== '');

function verify_pass($input, $pass, $passHash)
{
    if ($passHash !== '') {
        return password_verify($input, $passHash);
    }
    return ($pass !== '' && hash_equals($pass, $input));
}

function auth_token($pass, $passHash)
{
    return hash_hmac('sha256', 'cosmini-stats-v1', $passHash !== '' ? $passHash : $pass);
}

function page_shell($title, $body)
{
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
        . '<style>' . css() . '</style></head><body>' . $body . '</body></html>';
    exit;
}

function css()
{
    return <<<CSS
*{box-sizing:border-box}
body{margin:0;background:#F8FAFC;color:#0F172A;font-family:"Hiragino Kaku Gothic ProN","Noto Sans JP","Yu Gothic",sans-serif;padding:16px}
.wrap{max-width:960px;margin:0 auto}
h1{font-size:20px;margin:0 0 4px}
.sub{color:#64748B;font-size:12px;margin:0 0 20px}
.card{background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:16px;margin-bottom:16px;box-shadow:0 1px 2px rgba(15,23,42,.04)}
.card h2{font-size:14px;margin:0 0 12px;color:#334155;display:flex;align-items:center;gap:6px}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px}
.kpi{background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:14px}
.kpi .l{font-size:11px;color:#64748B;font-weight:700}
.kpi .v{font-size:26px;font-weight:900;color:#005DAA;line-height:1.2;margin-top:2px}
.kpi .s{font-size:11px;color:#94A3B8;margin-top:2px}
.kpi.big .v{color:#D91A2A}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:7px 8px;text-align:left;border-bottom:1px solid #F1F5F9}
th{color:#64748B;font-size:11px;font-weight:700}
td.n,th.n{text-align:right;font-variant-numeric:tabular-nums}
.bar{position:relative;background:#EFF6FF;border-radius:5px;height:10px;overflow:hidden;min-width:40px}
.bar span{position:absolute;inset:0 auto 0 0;background:#005DAA;border-radius:5px}
.bar.red span{background:#D91A2A}
.muted{color:#94A3B8;font-size:12px}
.row{display:flex;gap:16px;flex-wrap:wrap}
.row>.card{flex:1 1 300px}
form.login{max-width:340px;margin:12vh auto;background:#fff;border:1px solid #E2E8F0;border-radius:16px;padding:24px}
input[type=password]{width:100%;padding:10px 12px;border:1px solid #CBD5E1;border-radius:10px;font-size:16px}
button{margin-top:12px;width:100%;padding:11px;border:0;border-radius:10px;background:#005DAA;color:#fff;font-weight:800;font-size:15px;cursor:pointer}
.err{color:#D91A2A;font-size:12px;margin-top:8px}
.links{font-size:12px;display:flex;gap:14px;flex-wrap:wrap;margin-top:4px}
a{color:#005DAA}
.notice{background:#FEF3C7;border:1px solid #FCD34D;color:#92400E;border-radius:12px;padding:14px;font-size:13px;line-height:1.7}
code{background:#F1F5F9;padding:1px 5px;border-radius:4px;font-size:12px}
CSS;
}

// --- 初回セットアップ（パスワード未設定なら画面上で決める）--------------
if (!$hasPassword) {
    $setupError = '';
    if (isset($_POST['newpw'])) {
        $pw1 = (string) $_POST['newpw'];
        $pw2 = isset($_POST['newpw2']) ? (string) $_POST['newpw2'] : '';
        if (mb_strlen($pw1) < 6) {
            $setupError = 'パスワードは6文字以上にしてください';
        } elseif ($pw1 !== $pw2) {
            $setupError = '確認用のパスワードが一致しません';
        } else {
            if (!is_dir($DATA_DIR)) {
                @mkdir($DATA_DIR, 0755, true);
            }
            if (!is_dir($DATA_DIR) || !is_writable($DATA_DIR)) {
                $setupError = 'サーバーの data フォルダに書き込めませんでした。パーミッションをご確認ください。';
            } else {
                $hash = password_hash($pw1, PASSWORD_DEFAULT);
                $php = "<?php\n// アクセス解析ページのパスワード（ハッシュ化済み。元のパスワードは保存されません）\n"
                     . "// パスワードを忘れた場合はこのファイルを削除すると再設定できます。\n"
                     . "return array('hash' => '" . str_replace("'", "\\'", $hash) . "');\n";
                if (@file_put_contents($AUTH_FILE, $php, LOCK_EX) === false) {
                    $setupError = 'パスワードの保存に失敗しました。';
                } else {
                    @chmod($AUTH_FILE, 0600);
                    setcookie($COOKIE, hash_hmac('sha256', 'cosmini-stats-v1', $hash), time() + 60 * 60 * 24 * 30, '/', '', !empty($_SERVER['HTTPS']), true);
                    header('Location: stats.php');
                    exit;
                }
            }
        }
    }

    page_shell('アクセス解析｜初回設定',
        '<form class="login" method="post"><h1>アクセス解析</h1>'
        . '<p class="sub">最初にパスワードを決めてください。<br>次回からはこのパスワードで開けます。</p>'
        . '<input type="password" name="newpw" placeholder="新しいパスワード（6文字以上）" autofocus required>'
        . '<div style="height:8px"></div>'
        . '<input type="password" name="newpw2" placeholder="もう一度入力" required>'
        . '<button type="submit">このパスワードで設定する</button>'
        . ($setupError ? '<div class="err">' . htmlspecialchars($setupError, ENT_QUOTES, 'UTF-8') . '</div>' : '')
        . '<p class="muted" style="margin-top:14px;line-height:1.6">'
        . '※ 他の人に先に設定されないよう、公開後はお早めに設定してください。<br>'
        . '※ 忘れた場合はサーバーの <code>data/stats-auth.php</code> を削除すると再設定できます。'
        . '</p>'
        . '</form>');
}

// --- ログイン ---------------------------------------------------------
$token = auth_token($pass, $passHash);

if (isset($_GET['logout'])) {
    setcookie($COOKIE, '', time() - 3600, '/');
    header('Location: stats.php');
    exit;
}

$authed = isset($_COOKIE[$COOKIE]) && hash_equals($token, (string) $_COOKIE[$COOKIE]);
$loginError = '';

if (!$authed && isset($_POST['pw'])) {
    if (verify_pass((string) $_POST['pw'], $pass, $passHash)) {
        setcookie($COOKIE, $token, time() + 60 * 60 * 24 * 30, '/', '', !empty($_SERVER['HTTPS']), true);
        header('Location: stats.php');
        exit;
    }
    $loginError = 'パスワードが違います';
    usleep(600000);
}

if (!$authed) {
    page_shell('アクセス解析｜ログイン',
        '<form class="login" method="post"><h1>アクセス解析</h1>'
        . '<p class="sub">COS mini 国分店</p>'
        . '<input type="password" name="pw" placeholder="パスワード" autofocus required>'
        . '<button type="submit">ログイン</button>'
        . ($loginError ? '<div class="err">' . $loginError . '</div>' : '')
        . '</form>');
}

// --- データ読み込み ---------------------------------------------------
$d = array();
if (is_file($DATA_FILE)) {
    $d = json_decode((string) @file_get_contents($DATA_FILE), true);
}
if (!is_array($d)) {
    $d = array();
}
$d += array('total' => 0, 'days' => array(), 'pages' => array(), 'refs' => array(), 'since' => '', 'updated' => '');
$days = is_array($d['days']) ? $d['days'] : array();
ksort($days);

function day_val($days, $date, $key)
{
    return isset($days[$date][$key]) ? (int) $days[$date][$key] : 0;
}
function sum_range($days, $from, $to, $key)
{
    $s = 0;
    foreach ($days as $date => $v) {
        if ($date >= $from && $date <= $to) {
            $s += isset($v[$key]) ? (int) $v[$key] : 0;
        }
    }
    return $s;
}

$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$week_from = date('Y-m-d', strtotime('-6 days'));
$month_from = date('Y-m-01');
$prev_month_from = date('Y-m-01', strtotime('first day of last month'));
$prev_month_to   = date('Y-m-t', strtotime('first day of last month'));

// --- CSV出力 ----------------------------------------------------------
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=Shift_JIS');
    header('Content-Disposition: attachment; filename="cosmini-access-' . date('Ymd') . '.csv"');
    $rows = array(array('日付', 'ページビュー', '訪問者数', 'モバイル'));
    foreach ($days as $date => $v) {
        $rows[] = array($date, isset($v['pv']) ? (int) $v['pv'] : 0, isset($v['uv']) ? (int) $v['uv'] : 0, isset($v['mb']) ? (int) $v['mb'] : 0);
    }
    foreach ($rows as $r) {
        echo mb_convert_encoding(implode(',', $r), 'SJIS-win', 'UTF-8') . "\r\n";
    }
    exit;
}

// --- 直近30日グラフ ---------------------------------------------------
$chart = array();
$maxPv = 1;
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime('-' . $i . ' days'));
    $pv = day_val($days, $date, 'pv');
    $uv = day_val($days, $date, 'uv');
    $chart[] = array('date' => $date, 'pv' => $pv, 'uv' => $uv);
    if ($pv > $maxPv) {
        $maxPv = $pv;
    }
}

$todayHours = isset($days[$today]['hours']) && is_array($days[$today]['hours']) ? $days[$today]['hours'] : array_fill(0, 24, 0);
$maxHour = max(1, max($todayHours));

$pages = is_array($d['pages']) ? $d['pages'] : array();
arsort($pages);
$pages = array_slice($pages, 0, 10, true);
$refs = is_array($d['refs']) ? $d['refs'] : array();
arsort($refs);
$refs = array_slice($refs, 0, 10, true);

$monthPv = sum_range($days, $month_from, $today, 'pv');
$prevMonthPv = sum_range($days, $prev_month_from, $prev_month_to, 'pv');
$monthMb = sum_range($days, $month_from, $today, 'mb');
$mobileRate = $monthPv > 0 ? round($monthMb * 100 / $monthPv) : 0;

$wd = array('日', '月', '火', '水', '木', '金', '土');
function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>アクセス解析｜COS mini 国分店</title>
<style><?php echo css(); ?></style>
</head>
<body>
<div class="wrap">
    <h1>アクセス解析</h1>
    <p class="sub">
        COS mini 国分店　/　最終更新：<?php echo h($d['updated'] !== '' ? $d['updated'] : '—'); ?>
        <?php if ($d['since'] !== '') { ?>　/　計測開始：<?php echo h($d['since']); ?><?php } ?>
    </p>

    <?php if ((int) $d['total'] === 0) { ?>
    <div class="notice">
        まだアクセスが記録されていません。<br>
        サイト（index.html）を一度ブラウザで開くと、ここに数値が表示されます。<br>
        表示されない場合は、サーバー上に <code>count.php</code> がアップロードされているかご確認ください。
    </div>
    <?php } ?>

    <div class="kpis">
        <div class="kpi"><div class="l">今日</div><div class="v"><?php echo number_format(day_val($days, $today, 'pv')); ?></div><div class="s">訪問者 <?php echo number_format(day_val($days, $today, 'uv')); ?> 人</div></div>
        <div class="kpi"><div class="l">昨日</div><div class="v"><?php echo number_format(day_val($days, $yesterday, 'pv')); ?></div><div class="s">訪問者 <?php echo number_format(day_val($days, $yesterday, 'uv')); ?> 人</div></div>
        <div class="kpi"><div class="l">直近7日</div><div class="v"><?php echo number_format(sum_range($days, $week_from, $today, 'pv')); ?></div><div class="s">訪問者 <?php echo number_format(sum_range($days, $week_from, $today, 'uv')); ?> 人</div></div>
        <div class="kpi"><div class="l">今月（<?php echo (int) date('n'); ?>月）</div><div class="v"><?php echo number_format($monthPv); ?></div><div class="s">先月 <?php echo number_format($prevMonthPv); ?></div></div>
        <div class="kpi big"><div class="l">累計</div><div class="v"><?php echo number_format((int) $d['total']); ?></div><div class="s">スマホ比率 <?php echo (int) $mobileRate; ?>%</div></div>
    </div>

    <div class="card">
        <h2>📈 直近30日のアクセス</h2>
        <table>
            <tr><th>日付</th><th></th><th class="n">PV</th><th class="n">訪問者</th></tr>
            <?php foreach (array_reverse($chart) as $c) {
                $w = $maxPv > 0 ? round($c['pv'] * 100 / $maxPv) : 0;
                $isToday = ($c['date'] === $today); ?>
            <tr<?php echo $isToday ? ' style="background:#F8FAFC"' : ''; ?>>
                <td style="white-space:nowrap"><?php echo h(date('n/j', strtotime($c['date']))); ?>（<?php echo $wd[(int) date('w', strtotime($c['date']))]; ?>）</td>
                <td style="width:55%"><div class="bar"><span style="width:<?php echo $w; ?>%"></span></div></td>
                <td class="n"><?php echo number_format($c['pv']); ?></td>
                <td class="n"><?php echo number_format($c['uv']); ?></td>
            </tr>
            <?php } ?>
        </table>
    </div>

    <div class="row">
        <div class="card">
            <h2>🕒 今日の時間帯別アクセス</h2>
            <table>
                <?php for ($hh = 0; $hh < 24; $hh++) {
                    $v = (int) $todayHours[$hh];
                    if ($v === 0 && $hh > (int) date('G')) { continue; } ?>
                <tr>
                    <td style="white-space:nowrap;width:60px"><?php echo sprintf('%02d時', $hh); ?></td>
                    <td><div class="bar red"><span style="width:<?php echo round($v * 100 / $maxHour); ?>%"></span></div></td>
                    <td class="n" style="width:50px"><?php echo number_format($v); ?></td>
                </tr>
                <?php } ?>
            </table>
        </div>

        <div class="card">
            <h2>🔗 流入元（どこから来たか）</h2>
            <?php if (!$refs) { ?>
                <p class="muted">まだデータがありません。（直接アクセス・ブックマーク・アプリ内ブラウザからの訪問は流入元が記録されません）</p>
            <?php } else { ?>
            <table>
                <tr><th>サイト</th><th class="n">件数</th></tr>
                <?php foreach ($refs as $host => $n) { ?>
                <tr><td><?php echo h($host); ?></td><td class="n"><?php echo number_format((int) $n); ?></td></tr>
                <?php } ?>
            </table>
            <?php } ?>

            <h2 style="margin-top:18px">📄 よく見られたページ</h2>
            <table>
                <?php foreach ($pages as $p => $n) { ?>
                <tr><td><?php echo h($p); ?></td><td class="n"><?php echo number_format((int) $n); ?></td></tr>
                <?php } ?>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>🩺 動作チェック（うまく数えられないときはここを確認）</h2>
        <?php
        $indexFile = __DIR__ . '/index.html';
        $indexHas  = is_file($indexFile) && strpos((string) @file_get_contents($indexFile), 'count.php') !== false;
        $dirOk     = is_dir($DATA_DIR);
        $writeOk   = $dirOk && is_writable($DATA_DIR);
        $jsonOk    = is_file($DATA_FILE);
        $checks = array(
            array('計測ファイル（count.php）', is_file(__DIR__ . '/count.php'), is_file(__DIR__ . '/count.php') ? 'あります' : 'サーバーにありません'),
            array('サイトの計測タグ', $indexHas, $indexHas ? 'index.html に入っています' : 'index.html に入っていません（デプロイが未反映かもしれません）'),
            array('data フォルダ', $dirOk, $dirOk ? 'あります' : 'ありません'),
            array('data への書き込み', $writeOk, $writeOk ? 'できます' : 'できません（FTPで data フォルダの属性を 777 にしてください）'),
            array('集計ファイル', $jsonOk, $jsonOk ? ('あります（最終更新 ' . date('n/j H:i', (int) @filemtime($DATA_FILE)) . '）') : 'まだ作られていません（アクセスが1件も記録されていません）'),
        );
        ?>
        <table>
            <?php foreach ($checks as $c) { ?>
            <tr>
                <td style="width:44%"><?php echo h($c[0]); ?></td>
                <td style="width:32px"><?php echo $c[1] ? '<span style="color:#16A34A;font-weight:900">OK</span>' : '<span style="color:#D91A2A;font-weight:900">NG</span>'; ?></td>
                <td class="muted"><?php echo h($c[2]); ?></td>
            </tr>
            <?php } ?>
            <tr><td>サイトの最終更新</td><td></td><td class="muted"><?php echo is_file($indexFile) ? h(date('Y-m-d H:i', (int) @filemtime($indexFile))) : '—'; ?></td></tr>
            <tr><td>PHP バージョン</td><td></td><td class="muted"><?php echo h(PHP_VERSION); ?></td></tr>
        </table>

        <p class="muted" style="margin-top:14px">
            下のボタンを押すと、このブラウザから実際に1件送信してみます。<br>
            <code>"ok":true, "counted":true</code> と出れば計測は正常です。
        </p>
        <button type="button" id="selftest">テスト送信してみる</button>
        <pre id="selftest-out" style="white-space:pre-wrap;word-break:break-all;background:#F1F5F9;border-radius:8px;padding:10px;font-size:12px;margin-top:10px;display:none"></pre>
        <script>
        document.getElementById('selftest').addEventListener('click', function () {
            var out = document.getElementById('selftest-out');
            out.style.display = 'block';
            out.textContent = '送信中…';
            fetch('count.php?p=/self-test&w=' + (window.screen ? window.screen.width : 0) + '&_=' + Date.now(), { cache: 'no-store' })
                .then(function (r) { return r.text().then(function (t) { return { s: r.status, t: t }; }); })
                .then(function (r) {
                    out.textContent = 'HTTP ' + r.s + '\n' + r.t
                        + (r.s === 200 && r.t.indexOf('"ok":true') !== -1
                            ? '\n\n→ 計測は正常に動いています。この画面を再読み込みすると数値が増えます。'
                            : '\n\n→ うまくいっていません。この内容をそのままお知らせください。');
                })
                .catch(function (e) { out.textContent = 'エラー: ' + e + '\n\n→ この内容をそのままお知らせください。'; });
        });
        </script>
    </div>

    <div class="card">
        <h2>⬇️ データ</h2>
        <div class="links">
            <a href="?csv=1">日別データをCSVでダウンロード（Excel用）</a>
            <a href="?logout=1">ログアウト</a>
        </div>
        <p class="muted" style="margin-top:10px">
            ※ 保存しているのはアクセス数だけです。氏名・IPアドレスなどの個人情報は保存していません（重複判定用にハッシュ化した値のみ一時保持）。
        </p>
    </div>
</div>
</body>
</html>
