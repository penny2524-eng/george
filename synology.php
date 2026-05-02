<?php
declare(strict_types=1);

// ── Synology DSM config ───────────────────────────────────────────────────────

const SY_HOST = '192.168.50.99';
const SY_PORT = '5000';                         // 5000 = HTTP, 5001 = HTTPS
const SY_USER = 'george';
const SY_PASS = 'Penny2000!';
const SY_BASE = 'http://' . SY_HOST . ':' . SY_PORT . '/webapi';

$refreshSec = (int) ($_GET['refresh'] ?? 30);
if ($refreshSec < 0 || $refreshSec > 300) $refreshSec = 30;

// ── DSM API helper ────────────────────────────────────────────────────────────

function syGet(string $endpoint, array $params, string $sid = ''): array
{
    if ($sid !== '') $params['_sid'] = $sid;
    $url = SY_BASE . '/' . ltrim($endpoint, '/') . '?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,   // self-signed cert on local NAS
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $err !== '') return ['success' => false, '_error' => 'cURL: ' . $err];
    if ($code >= 400) return ['success' => false, '_error' => "HTTP $code"];

    $data = json_decode($raw, associative: true);
    if (!is_array($data)) return ['success' => false, '_error' => 'Invalid JSON'];
    if (!($data['success'] ?? false)) {
        $code = $data['error']['code'] ?? 0;
        $msgs = [
            100 => 'Unknown error', 101 => 'Invalid parameter', 102 => 'API not found',
            103 => 'Method not found', 104 => 'Version not supported', 105 => 'Permission denied',
            400 => 'Invalid password/account', 401 => 'Account disabled', 403 => 'Permission denied',
            404 => '2-step verification required',
        ];
        return ['success' => false, '_error' => $msgs[$code] ?? "DSM error $code"];
    }
    return $data['data'] ?? ['success' => true];
}

// ── Login ─────────────────────────────────────────────────────────────────────

$loginResult = syGet('auth.cgi', [
    'api'     => 'SYNO.API.Auth',
    'version' => '3',
    'method'  => 'login',
    'account' => SY_USER,
    'passwd'  => SY_PASS,
    'session' => 'PHPMonitor',
    'format'  => 'sid',
]);

$sid         = $loginResult['sid'] ?? '';
$loginOk     = $sid !== '';
$loginError  = $loginOk ? '' : ($loginResult['_error'] ?? 'Login failed');

// ── Fetch all data (only if logged in) ───────────────────────────────────────

$sysInfo     = $loginOk ? syGet('entry.cgi', ['api' => 'SYNO.Core.System',             'version' => '3', 'method' => 'info'],      $sid) : [];
$utilization = $loginOk ? syGet('entry.cgi', ['api' => 'SYNO.Core.System.Utilization', 'version' => '1', 'method' => 'get'],       $sid) : [];
$storage     = $loginOk ? syGet('entry.cgi', ['api' => 'SYNO.Storage.CGI.Storage',     'version' => '1', 'method' => 'load_info'], $sid) : [];
$disks       = $loginOk ? syGet('entry.cgi', ['api' => 'SYNO.Storage.CGI.Disk',        'version' => '1', 'method' => 'list',       'limit' => 30], $sid) : [];
$shares      = $loginOk ? syGet('entry.cgi', ['api' => 'SYNO.Core.Share',              'version' => '1', 'method' => 'list',       'additional' => '["size","owner"]'], $sid) : [];
$network     = $loginOk ? syGet('entry.cgi', ['api' => 'SYNO.Core.Network.Interface',  'version' => '1', 'method' => 'get'],       $sid) : [];
$upgrade     = $loginOk ? syGet('entry.cgi', ['api' => 'SYNO.Core.Upgrade',            'version' => '1', 'method' => 'check'],     $sid) : [];
$services    = $loginOk ? syGet('entry.cgi', ['api' => 'SYNO.Core.Service',            'version' => '1', 'method' => 'list'],      $sid) : [];

// Logout (best-effort — fire and forget)
if ($loginOk) {
    syGet('auth.cgi', ['api' => 'SYNO.API.Auth', 'version' => '3', 'method' => 'logout', 'session' => 'PHPMonitor'], $sid);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmtBytes(int|float|string $bytes, int $dec = 1): string {
    $b = (float) $bytes;
    if ($b >= 1_099_511_627_776) return round($b / 1_099_511_627_776, $dec) . ' TB';
    if ($b >= 1_073_741_824)     return round($b / 1_073_741_824,     $dec) . ' GB';
    if ($b >= 1_048_576)         return round($b / 1_048_576,         $dec) . ' MB';
    if ($b >= 1024)              return round($b / 1024,              $dec) . ' KB';
    return (int)$b . ' B';
}

function pctColor(int $pct): string {
    if ($pct >= 90) return '#f85149';
    if ($pct >= 75) return '#d29922';
    return '#3fb950';
}

function statusTag(string $status): string {
    $s = strtolower($status);
    $cls = match(true) {
        in_array($s, ['normal','good','connected','running','enabled','active']) => 'tag-green',
        in_array($s, ['warning','degraded','initializing'])                     => 'tag-amber',
        in_array($s, ['error','critical','crashed','failed','disconnected'])    => 'tag-red',
        default => '',
    };
    return '<span class="tag ' . $cls . '">' . e($status) . '</span>';
}

// ── Alert engine ──────────────────────────────────────────────────────────────

$alerts = [];
function syAlert(string $level, string $cat, string $msg): void {
    global $alerts;
    $alerts[] = ['level' => $level, 'cat' => $cat, 'msg' => $msg];
}

if (!$loginOk) {
    syAlert('critical', 'System', "Cannot connect to Synology: $loginError");
}

// CPU
$cpuLoad = (int) ($utilization['cpu']['user_load'] ?? 0)
         + (int) ($utilization['cpu']['system_load'] ?? 0);
if ($cpuLoad >= 90) syAlert('critical', 'CPU', "CPU usage critical: {$cpuLoad}%");
elseif ($cpuLoad >= 75) syAlert('warning', 'CPU', "CPU usage high: {$cpuLoad}%");

// Memory — DSM returns all values in KB
// Use real_usage (pre-computed by DSM, excludes buffer/cache) for the percentage.
// For the "used" display bytes, subtract free + buffer + cached so it matches DSM UI.
$memTotal  = (int) ($utilization['memory']['total_real'] ?? 0);
$memFree   = (int) ($utilization['memory']['avail_real'] ?? 0);
$memBuffer = (int) ($utilization['memory']['buffer']     ?? 0);
$memCached = (int) ($utilization['memory']['cached']     ?? 0);
$memUsed   = max(0, $memTotal - $memFree - $memBuffer - $memCached);  // app-used only
$memPct    = $memTotal > 0
    ? (int) ($utilization['memory']['real_usage'] ?? round($memUsed / $memTotal * 100))
    : 0;
if ($memPct >= 90) syAlert('critical', 'Memory', "Memory usage critical: {$memPct}%");
elseif ($memPct >= 80) syAlert('warning', 'Memory', "Memory usage high: {$memPct}%");

// System temperature
$sysTempRaw = $sysInfo['temperature'] ?? $sysInfo['sys_temp'] ?? null;
if ($sysTempRaw !== null) {
    $sysTemp = (int) $sysTempRaw;
    if ($sysTemp >= 70) syAlert('critical', 'Temperature', "System temperature critical: {$sysTemp}°C");
    elseif ($sysTemp >= 55) syAlert('warning', 'Temperature', "System temperature high: {$sysTemp}°C");
}

// Volumes
$volumes = $storage['volumes'] ?? [];
foreach ($volumes as $vol) {
    $vName   = $vol['display_name'] ?? $vol['id'] ?? '?';
    $vStatus = strtolower($vol['status'] ?? 'unknown');
    if (!in_array($vStatus, ['normal',''])) {
        syAlert('critical', 'Volume', "Volume '{$vName}' status: {$vol['status']}");
    }
    $vTotal = (float) ($vol['size']['total'] ?? 0);
    $vUsed  = (float) ($vol['size']['used']  ?? 0);
    $vPct   = $vTotal > 0 ? (int) round($vUsed / $vTotal * 100) : 0;
    if ($vPct >= 90) syAlert('critical', 'Volume', "Volume '{$vName}' is {$vPct}% full");
    elseif ($vPct >= 80) syAlert('warning', 'Volume', "Volume '{$vName}' is {$vPct}% full");
}

// Disks
$diskList = $disks['disks'] ?? $disks['disk'] ?? [];
foreach ($diskList as $disk) {
    $dName   = $disk['name'] ?? $disk['id'] ?? '?';
    $dModel  = $disk['model'] ?? '';
    $dStatus = strtolower($disk['status'] ?? 'normal');
    if (!in_array($dStatus, ['normal','good',''])) {
        syAlert('critical', 'Disk', "Disk {$dName} ({$dModel}) status: {$disk['status']}");
    }
    $dTemp = (int) ($disk['temp'] ?? $disk['temperature'] ?? 0);
    if ($dTemp > 0) {
        if ($dTemp >= 55) syAlert('critical', 'Disk', "Disk {$dName} temperature critical: {$dTemp}°C");
        elseif ($dTemp >= 45) syAlert('warning', 'Disk', "Disk {$dName} temperature high: {$dTemp}°C");
    }
    $bad = (int) ($disk['exceed_bad_sector_thr'] ?? 0);
    if ($bad > 0) syAlert('critical', 'Disk', "Disk {$dName} has exceeded bad sector threshold");
}

// DSM update available
if (($upgrade['available'] ?? false) === true || ($upgrade['update'] ?? false) === true) {
    $ver = $upgrade['version'] ?? $upgrade['update_info']['version'] ?? '';
    syAlert('info', 'Update', "DSM update available" . ($ver ? ": $ver" : ''));
}

$critCount   = count(array_filter($alerts, fn($a) => $a['level'] === 'critical'));
$warnCount   = count(array_filter($alerts, fn($a) => $a['level'] === 'warning'));
$infoCount   = count(array_filter($alerts, fn($a) => $a['level'] === 'info'));
$totalAlerts = count($alerts);

// ── View data ─────────────────────────────────────────────────────────────────

$hostname   = e($sysInfo['hostname']        ?? SY_HOST);
$model      = e($sysInfo['model']           ?? '—');
$dsmVersion = e($sysInfo['firmware_ver']    ?? $sysInfo['dsm_ver']   ?? '—');
$serial     = e($sysInfo['serial']          ?? '—');
$uptime     = e($sysInfo['uptime']          ?? '—');
$sysTemp    = isset($sysInfo['temperature']) ? (int)$sysInfo['temperature'] : (isset($sysInfo['sys_temp']) ? (int)$sysInfo['sys_temp'] : null);

$swapTotal  = (int) ($utilization['memory']['total_swap'] ?? 0);
$swapUsed   = $swapTotal - (int) ($utilization['memory']['avail_swap'] ?? 0);
$swapPct    = $swapTotal > 0 ? (int) round($swapUsed / $swapTotal * 100) : 0;

$netIfaces  = $network['interfaces'] ?? $network['interface'] ?? [];
$fetchedAt  = date('Y-m-d H:i:s');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Synology Monitor — <?= $hostname ?></title>
    <?php if ($refreshSec > 0): ?>
    <meta http-equiv="refresh" content="<?= $refreshSec ?>">
    <?php endif; ?>
    <style>
        :root {
            --bg:      #0d1117;
            --surface: #161b22;
            --surface2:#1c2333;
            --border:  #30363d;
            --text:    #c9d1d9;
            --muted:   #8b949e;
            --blue:    #58a6ff;
            --green:   #3fb950;
            --red:     #f85149;
            --amber:   #d29922;
            --purple:  #a371f7;
            --radius:  10px;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; font-size: 14px; }

        header {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 1rem 1.5rem;
            display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .75rem;
        }
        .header-left { display: flex; align-items: center; gap: .75rem; }
        .nas-icon    { font-size: 1.8rem; }
        .nas-name    { font-size: 1.25rem; font-weight: 700; color: #fff; }
        .nas-sub     { font-size: .78rem; color: var(--muted); margin-top: .1rem; }
        .header-right { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; }

        .badge { display: inline-flex; align-items: center; gap: .35rem; padding: .25rem .7rem; border-radius: 999px; font-size: .75rem; font-weight: 600; }
        .badge-green  { background: #0d1f0f; color: #3fb950; border: 1px solid #238636; }
        .badge-red    { background: #1f0d0d; color: #f85149; border: 1px solid #6e1a1a; }
        .badge-amber  { background: #1f1500; color: #d29922; border: 1px solid #7a4f00; }
        .badge-blue   { background: #0d1626; color: #58a6ff; border: 1px solid #1f6feb; }

        .refresh-form { display: flex; align-items: center; gap: .4rem; font-size: .78rem; color: var(--muted); }
        .refresh-form select { background: var(--surface2); border: 1px solid var(--border); color: var(--text); border-radius: 6px; padding: .2rem .4rem; font-size: .78rem; }

        .container { max-width: 1400px; margin: 0 auto; padding: 1.25rem 1.5rem; }

        .stat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: .75rem; margin-bottom: 1.25rem; }
        .stat-tile { background: var(--surface2); border: 1px solid var(--border); border-radius: var(--radius); padding: .9rem 1rem; }
        .stat-label { font-size: .72rem; color: var(--muted); margin-bottom: .25rem; }
        .stat-value { font-size: 1.5rem; font-weight: 700; color: #fff; }
        .stat-sub   { font-size: .72rem; color: var(--muted); margin-top: .15rem; }

        .progress-wrap  { margin-top: .4rem; }
        .progress-label { display: flex; justify-content: space-between; font-size: .7rem; color: var(--muted); margin-bottom: .2rem; }
        .progress-bar   { height: 6px; background: var(--border); border-radius: 3px; overflow: hidden; }
        .progress-fill  { height: 100%; border-radius: 3px; }

        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1rem 1.25rem; margin-bottom: 1.25rem; }
        .card-title { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); margin-bottom: .85rem; display: flex; align-items: center; gap: .5rem; }
        .card-title .dot { width: 7px; height: 7px; border-radius: 50%; background: var(--blue); }

        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem; }

        .tbl { width: 100%; border-collapse: collapse; font-size: .82rem; }
        .tbl th { text-align: left; padding: .45rem .65rem; color: var(--muted); font-weight: 600; font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; border-bottom: 1px solid var(--border); }
        .tbl td { padding: .5rem .65rem; border-bottom: 1px solid #21272e; vertical-align: middle; }
        .tbl tr:last-child td { border-bottom: none; }
        .tbl tr:hover td { background: var(--surface2); }

        .mono  { font-family: 'Consolas','Courier New',monospace; font-size: .8rem; color: var(--blue); }
        .tag   { display: inline-block; padding: .1rem .45rem; border-radius: 4px; font-size: .68rem; font-weight: 700; background: var(--surface2); border: 1px solid var(--border); color: var(--muted); }
        .tag-green { background: #0d1f0f; color: #3fb950; border-color: #238636; }
        .tag-red   { background: #1f0d0d; color: #f85149; border-color: #6e1a1a; }
        .tag-amber { background: #1f1500; color: #d29922; border-color: #7a4f00; }
        .tag-blue  { background: #0d1626; color: #58a6ff; border-color: #1f6feb; }

        .dot-green { display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--green);margin-right:.3rem; }
        .dot-red   { display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--red);  margin-right:.3rem; }
        .dot-gray  { display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--muted);margin-right:.3rem; }

        /* Volume bar */
        .vol-row { background: var(--surface2); border: 1px solid var(--border); border-radius: 8px; padding: .9rem 1.1rem; margin-bottom: .75rem; }
        .vol-head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .5rem; margin-bottom: .5rem; }
        .vol-name { font-weight: 700; font-size: .95rem; }
        .vol-stats { display: flex; gap: 1.25rem; font-size: .8rem; margin-top: .45rem; }

        /* Alerts panel */
        .alert-panel { border-radius: var(--radius); border: 1px solid var(--border); margin-bottom: 1.25rem; overflow: hidden; }
        .alert-panel-header { display: flex; align-items: center; justify-content: space-between; padding: .7rem 1.1rem; background: var(--surface2); border-bottom: 1px solid var(--border); cursor: pointer; user-select: none; }
        .alert-panel-header h3 { font-size: .9rem; font-weight: 700; display: flex; align-items: center; gap: .5rem; }
        .alert-panel-body { background: var(--surface); }
        .alert-row { display: flex; align-items: flex-start; gap: .75rem; padding: .55rem 1.1rem; border-bottom: 1px solid #1c2333; font-size: .82rem; }
        .alert-row:last-child { border-bottom: none; }
        .alert-row.critical { border-left: 3px solid #f85149; }
        .alert-row.warning  { border-left: 3px solid #d29922; }
        .alert-row.info     { border-left: 3px solid #58a6ff; }
        .alert-icon { font-size: 1rem; flex-shrink: 0; margin-top: .05rem; }
        .alert-cat  { min-width: 90px; font-weight: 600; font-size: .75rem; }
        .alert-cat.critical { color: #f85149; }
        .alert-cat.warning  { color: #d29922; }
        .alert-cat.info     { color: #58a6ff; }
        .alert-msg  { color: var(--text); flex: 1; }
        .acb { display: inline-flex; align-items: center; justify-content: center; min-width: 22px; height: 22px; border-radius: 999px; font-size: .72rem; font-weight: 700; padding: 0 .4rem; }
        .acb-red   { background: #6e1a1a; color: #f85149; }
        .acb-amber { background: #7a4f00; color: #d29922; }
        .acb-blue  { background: #0d2040; color: #58a6ff; }
        .acb-green { background: #0d1f0f; color: #3fb950; }

        .error-box { background: #1f0d0d; border: 1px solid #6e1a1a; border-radius: 8px; padding: .65rem 1rem; color: #f85149; font-size: .8rem; margin-bottom: .75rem; }
        .info-box  { background: #0d1626; border: 1px solid #1f6feb; border-radius: 8px; padding: .65rem 1rem; color: #58a6ff; font-size: .8rem; margin-bottom: .75rem; }
        .updated   { font-size: .72rem; color: var(--muted); text-align: right; margin-top: .5rem; }

        @media (max-width: 900px) {
            .grid-2 { grid-template-columns: 1fr; }
            .stat-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

<!-- ── Header ────────────────────────────────────────────────────────────── -->
<header>
    <div class="header-left">
        <span class="nas-icon">🖥️</span>
        <div>
            <div class="nas-name"><?= $hostname ?></div>
            <div class="nas-sub"><?= e(SY_HOST) ?>:<?= e(SY_PORT) ?> &bull; <?= $model ?> &bull; DSM <?= $dsmVersion ?></div>
        </div>
    </div>
    <div class="header-right">
        <?php if ($loginOk): ?>
        <span class="badge badge-green">● Online</span>
        <?php if ($uptime !== '—'): ?>
        <span class="badge badge-blue">⏱ <?= $uptime ?>s uptime</span>
        <?php endif; ?>
        <?php else: ?>
        <span class="badge badge-red">● Unreachable</span>
        <?php endif; ?>

        <?php if ($critCount > 0): ?>
        <span class="badge badge-red">⚠️ <?= $critCount ?> critical</span>
        <?php elseif ($warnCount > 0): ?>
        <span class="badge badge-amber">⚠️ <?= $warnCount ?> warning</span>
        <?php elseif ($loginOk): ?>
        <span class="badge badge-green">✓ No alerts</span>
        <?php endif; ?>

        <form class="refresh-form" method="GET">
            <span>Refresh:</span>
            <select name="refresh" onchange="this.form.submit()">
                <option value="0"  <?= $refreshSec===0  ? 'selected':'' ?>>Off</option>
                <option value="10" <?= $refreshSec===10 ? 'selected':'' ?>>10 s</option>
                <option value="30" <?= $refreshSec===30 ? 'selected':'' ?>>30 s</option>
                <option value="60" <?= $refreshSec===60 ? 'selected':'' ?>>60 s</option>
            </select>
        </form>
    </div>
</header>

<div class="container">

<?php if (!$loginOk): ?>
<div class="error-box" style="margin-top:1rem;">
    ⚠️ Cannot connect to Synology at <?= e(SY_HOST) ?>: <?= e($loginError) ?>
    <br><small>Ensure DSM Web API is enabled: Control Panel → Terminal &amp; SNMP → Web API, and this page is accessed from the same network.</small>
</div>
<?php endif; ?>

<!-- ── System Stats ───────────────────────────────────────────────────────── -->
<div class="stat-grid">

    <!-- CPU -->
    <div class="stat-tile">
        <div class="stat-label">CPU Load</div>
        <div class="stat-value" style="color:<?= pctColor($cpuLoad) ?>"><?= $cpuLoad ?>%</div>
        <div class="progress-wrap">
            <div class="progress-bar"><div class="progress-fill" style="width:<?= $cpuLoad ?>%;background:<?= pctColor($cpuLoad) ?>"></div></div>
        </div>
        <?php $load1 = $utilization['cpu']['1min_load'] ?? null; ?>
        <div class="stat-sub"><?= $load1 !== null ? "1-min avg: {$load1}%" : '—' ?></div>
    </div>

    <!-- Memory -->
    <div class="stat-tile">
        <div class="stat-label">Memory</div>
        <div class="stat-value" style="color:<?= pctColor($memPct) ?>"><?= $memPct ?>%</div>
        <div class="progress-wrap">
            <div class="progress-label">
                <span><?= fmtBytes($memUsed * 1024) ?> used</span>
                <span><?= fmtBytes($memTotal * 1024) ?> total</span>
            </div>
            <div class="progress-bar"><div class="progress-fill" style="width:<?= $memPct ?>%;background:<?= pctColor($memPct) ?>"></div></div>
        </div>
    </div>

    <!-- Swap -->
    <?php if ($swapTotal > 0): ?>
    <div class="stat-tile">
        <div class="stat-label">Swap</div>
        <div class="stat-value" style="color:<?= pctColor($swapPct) ?>"><?= $swapPct ?>%</div>
        <div class="progress-wrap">
            <div class="progress-label">
                <span><?= fmtBytes($swapUsed * 1024) ?> used</span>
                <span><?= fmtBytes($swapTotal * 1024) ?> total</span>
            </div>
            <div class="progress-bar"><div class="progress-fill" style="width:<?= $swapPct ?>%;background:<?= pctColor($swapPct) ?>"></div></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- System temperature -->
    <?php if ($sysTemp !== null): ?>
    <div class="stat-tile">
        <div class="stat-label">System Temp</div>
        <div class="stat-value" style="color:<?= $sysTemp >= 70 ? '#f85149' : ($sysTemp >= 55 ? '#d29922' : '#3fb950') ?>"><?= $sysTemp ?>°C</div>
    </div>
    <?php endif; ?>

    <!-- Volumes summary -->
    <?php
    $volCount  = count($volumes);
    $volNormal = count(array_filter($volumes, fn($v) => strtolower($v['status'] ?? '') === 'normal'));
    ?>
    <div class="stat-tile">
        <div class="stat-label">Volumes</div>
        <div class="stat-value" style="color:<?= $volNormal < $volCount ? '#f85149' : '#3fb950' ?>"><?= $volNormal ?>/<?= $volCount ?></div>
        <div class="stat-sub"><?= $volNormal < $volCount ? ($volCount - $volNormal) . ' degraded' : 'all normal' ?></div>
    </div>

    <!-- Disks summary -->
    <?php
    $dskTotal  = count($diskList);
    $dskNormal = count(array_filter($diskList, fn($d) => in_array(strtolower($d['status'] ?? ''), ['normal','good'])));
    ?>
    <div class="stat-tile">
        <div class="stat-label">Disks</div>
        <div class="stat-value" style="color:<?= $dskNormal < $dskTotal ? '#f85149' : '#3fb950' ?>"><?= $dskNormal ?>/<?= $dskTotal ?></div>
        <div class="stat-sub"><?= $dskNormal < $dskTotal ? ($dskTotal - $dskNormal) . ' issue(s)' : 'all healthy' ?></div>
    </div>

    <!-- Alert tile -->
    <div class="stat-tile" style="<?= $critCount > 0 ? 'border-color:#6e1a1a;background:#1f0d0d;' : ($warnCount > 0 ? 'border-color:#7a4f00;background:#1f1500;' : 'border-color:#238636;background:#0d1f0f;') ?>">
        <div class="stat-label">Alerts</div>
        <div class="stat-value" style="color:<?= $critCount > 0 ? '#f85149' : ($warnCount > 0 ? '#d29922' : '#3fb950') ?>"><?= $totalAlerts ?></div>
        <div class="stat-sub">
            <?= $totalAlerts === 0 ? '✅ All clear' : "{$critCount} crit &bull; {$warnCount} warn &bull; {$infoCount} info" ?>
        </div>
    </div>

</div>

<!-- ── Errors & Alerts ────────────────────────────────────────────────────── -->
<div class="alert-panel">
    <div class="alert-panel-header" onclick="toggleAlerts()">
        <h3>
            ⚠️ Errors &amp; Alerts
            <?php if ($critCount > 0): ?><span class="acb acb-red"><?= $critCount ?> critical</span><?php endif; ?>
            <?php if ($warnCount > 0): ?><span class="acb acb-amber"><?= $warnCount ?> warning</span><?php endif; ?>
            <?php if ($infoCount  > 0): ?><span class="acb acb-blue"><?= $infoCount ?> info</span><?php endif; ?>
            <?php if ($totalAlerts === 0): ?><span class="acb acb-green">✓ All clear</span><?php endif; ?>
        </h3>
        <span id="alertToggleIcon" style="color:var(--muted);font-size:.82rem;">▲ collapse</span>
    </div>
    <div class="alert-panel-body" id="alertPanelBody">
        <?php if (empty($alerts)): ?>
        <div class="alert-row info" style="border-left-color:#3fb950;">
            <span class="alert-icon">✅</span>
            <span class="alert-cat" style="color:#3fb950;">System</span>
            <span class="alert-msg">No errors or warnings detected.</span>
        </div>
        <?php else: ?>
        <?php foreach ($alerts as $a):
            $icon = match($a['level']) { 'critical' => '🔴', 'warning' => '🟡', default => '🔵' };
        ?>
        <div class="alert-row <?= e($a['level']) ?>">
            <span class="alert-icon"><?= $icon ?></span>
            <span class="alert-cat <?= e($a['level']) ?>"><?= e($a['cat']) ?></span>
            <span class="alert-msg"><?= e($a['msg']) ?></span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ── Storage Volumes ────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-title"><span class="dot"></span> Storage Volumes</div>
    <?php if (empty($volumes)): ?>
    <div class="info-box">No volume data returned — requires Storage Manager permission.</div>
    <?php else: ?>
    <?php foreach ($volumes as $vol):
        $vName   = $vol['display_name'] ?? $vol['id'] ?? '?';
        $vStatus = $vol['status'] ?? 'unknown';
        $vFs     = $vol['fs_type'] ?? '—';
        $vTotal  = (float) ($vol['size']['total'] ?? 0);
        $vUsed   = (float) ($vol['size']['used']  ?? 0);
        $vFree   = $vTotal - $vUsed;
        $vPct    = $vTotal > 0 ? (int) round($vUsed / $vTotal * 100) : 0;
        $barCol  = pctColor($vPct);
    ?>
    <div class="vol-row">
        <div class="vol-head">
            <div>
                <span class="vol-name"><?= e($vName) ?></span>
                <span class="tag tag-blue" style="margin-left:.4rem;"><?= e($vFs) ?></span>
                <?= statusTag($vStatus) ?>
            </div>
            <span style="font-size:1.5rem;font-weight:700;color:<?= $barCol ?>"><?= $vPct ?>%</span>
        </div>
        <div class="progress-bar" style="height:10px;margin-bottom:.5rem;">
            <div class="progress-fill" style="width:<?= $vPct ?>%;background:<?= $barCol ?>"></div>
        </div>
        <div class="vol-stats">
            <span>📦 Total: <strong><?= fmtBytes($vTotal) ?></strong></span>
            <span style="color:<?= $barCol ?>">🔴 Used: <strong><?= fmtBytes($vUsed) ?></strong></span>
            <span style="color:#3fb950">🟢 Free: <strong><?= fmtBytes($vFree) ?></strong></span>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ── Disks ──────────────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-title"><span class="dot"></span> Disk Health</div>
    <?php if (empty($diskList)): ?>
    <div class="info-box">No disk data returned — requires Storage Manager permission.</div>
    <?php else: ?>
    <table class="tbl">
        <thead>
            <tr>
                <th>Slot</th>
                <th>Model</th>
                <th>Serial</th>
                <th>Type</th>
                <th style="text-align:right">Capacity</th>
                <th style="text-align:center">Temp</th>
                <th>Status</th>
                <th>SMART</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($diskList as $disk):
            $dSlot   = $disk['name']         ?? $disk['id']     ?? '—';
            $dModel  = $disk['model']         ?? '—';
            $dSerial = $disk['serial']        ?? '—';
            $dType   = $disk['diskType']      ?? $disk['type']  ?? '—';
            $dSize   = (float) ($disk['size_total'] ?? $disk['capacity'] ?? 0);
            $dTemp   = (int)   ($disk['temp']        ?? $disk['temperature'] ?? 0);
            $dStatus = $disk['status']        ?? 'unknown';
            $dSmart  = $disk['smart_status']  ?? $disk['smartStatus'] ?? '—';
            $tempCol = $dTemp >= 55 ? '#f85149' : ($dTemp >= 45 ? '#d29922' : '#3fb950');
        ?>
        <tr>
            <td><strong><?= e($dSlot) ?></strong></td>
            <td><?= e($dModel) ?></td>
            <td><span class="mono"><?= e($dSerial) ?></span></td>
            <td><span class="tag tag-blue"><?= e($dType) ?></span></td>
            <td style="text-align:right"><?= $dSize > 0 ? fmtBytes($dSize) : '—' ?></td>
            <td style="text-align:center;color:<?= $tempCol ?>;font-weight:700"><?= $dTemp > 0 ? $dTemp . '°C' : '—' ?></td>
            <td><?= statusTag($dStatus) ?></td>
            <td><?= $dSmart !== '—' ? statusTag($dSmart) : '<span style="color:var(--muted)">—</span>' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- ── Network & Shared Folders ──────────────────────────────────────────── -->
<div class="grid-2">

<!-- Network Interfaces -->
<div class="card" style="margin-bottom:0">
    <div class="card-title"><span class="dot"></span> Network Interfaces</div>
    <?php if (empty($netIfaces)): ?>
    <div class="info-box">No network data returned.</div>
    <?php else: ?>
    <table class="tbl">
        <thead><tr><th>Name</th><th>IP Address</th><th>MAC</th><th style="text-align:right">TX</th><th style="text-align:right">RX</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($netIfaces as $iface):
            $ifName   = $iface['name']    ?? $iface['id'] ?? '—';
            $ifIP     = $iface['ip']      ?? ($iface['ipv4'][0]['address'] ?? '—');
            $ifMac    = $iface['mac']     ?? '—';
            $ifTx     = (int) ($iface['tx_rate']    ?? $iface['tx'] ?? 0);
            $ifRx     = (int) ($iface['rx_rate']    ?? $iface['rx'] ?? 0);
            $ifStatus = ($iface['enabled'] ?? true) ? (($iface['link'] ?? $iface['status'] ?? 'up') === 'up' ? 'up' : 'down') : 'disabled';
        ?>
        <tr>
            <td><strong><?= e($ifName) ?></strong></td>
            <td><span class="mono"><?= e($ifIP) ?></span></td>
            <td><span class="mono"><?= e($ifMac) ?></span></td>
            <td style="text-align:right"><?= $ifTx > 0 ? fmtBytes($ifTx) . '/s' : '—' ?></td>
            <td style="text-align:right"><?= $ifRx > 0 ? fmtBytes($ifRx) . '/s' : '—' ?></td>
            <td><?= statusTag($ifStatus) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Shared Folders -->
<div class="card" style="margin-bottom:0">
    <div class="card-title"><span class="dot"></span> Shared Folders</div>
    <?php
    $shareList = $shares['shares'] ?? $shares['share'] ?? [];
    if (empty($shareList)): ?>
    <div class="info-box">No shares returned — requires File Station / Shared Folder permission.</div>
    <?php else: ?>
    <table class="tbl">
        <thead><tr><th>Name</th><th>Path</th><th style="text-align:right">Used</th><th style="text-align:right">Total</th><th>Encrypt</th></tr></thead>
        <tbody>
        <?php foreach ($shareList as $share):
            $sName    = $share['name']           ?? '—';
            $sPath    = $share['vol_path']        ?? $share['path']  ?? '—';
            $sUsed    = (float) ($share['size']['used']  ?? 0);
            $sTotal   = (float) ($share['size']['total'] ?? 0);
            $sEnc     = ($share['encrypt'] ?? false) ? 'yes' : 'no';
        ?>
        <tr>
            <td><strong><?= e($sName) ?></strong></td>
            <td><span class="mono" style="font-size:.72rem"><?= e($sPath) ?></span></td>
            <td style="text-align:right"><?= $sUsed  > 0 ? fmtBytes($sUsed)  : '—' ?></td>
            <td style="text-align:right"><?= $sTotal > 0 ? fmtBytes($sTotal) : '—' ?></td>
            <td><?= $sEnc === 'yes' ? '<span class="tag tag-blue">🔒 yes</span>' : '<span class="tag">no</span>' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

</div><!-- /.grid-2 -->

<!-- ── System Info ───────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-title"><span class="dot"></span> System Information</div>
    <table class="tbl">
        <tbody>
        <?php
        $infoRows = [
            'Hostname'         => $hostname,
            'Model'            => $model,
            'Serial Number'    => $serial,
            'DSM Version'      => $dsmVersion,
            'Architecture'     => e($sysInfo['cpu_vendor'] ?? '—') . ' / ' . e($sysInfo['cpu_family'] ?? '—'),
            'CPU Cores'        => e((string)($sysInfo['cpu_cores'] ?? $utilization['cpu']['device'] ?? '—')),
            'System Temperature' => $sysTemp !== null ? "{$sysTemp}°C" : '—',
            'Uptime (seconds)' => $uptime,
            'DSM Update'       => ($upgrade['available'] ?? false) ? '<span class="tag tag-amber">Available: ' . e($upgrade['version'] ?? 'check DSM') . '</span>' : '<span class="tag tag-green">Up to date</span>',
        ];
        foreach ($infoRows as $label => $value): ?>
        <tr>
            <td style="color:var(--muted);width:200px;font-size:.78rem;font-weight:600"><?= e($label) ?></td>
            <td><?= $value ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ── Network Throughput (from utilization) ─────────────────────────────── -->
<?php
$netUtil = $utilization['network'] ?? [];
if (!empty($netUtil)): ?>
<div class="card">
    <div class="card-title"><span class="dot"></span> Network Throughput (real-time)</div>
    <table class="tbl">
        <thead><tr><th>Interface</th><th style="text-align:right">TX (bytes/s)</th><th style="text-align:right">RX (bytes/s)</th></tr></thead>
        <tbody>
        <?php foreach ($netUtil as $ni):
            $niName = $ni['device'] ?? $ni['name'] ?? '—';
            $niTx   = (int)($ni['tx'] ?? 0);
            $niRx   = (int)($ni['rx'] ?? 0);
        ?>
        <tr>
            <td><strong><?= e($niName) ?></strong></td>
            <td style="text-align:right"><?= fmtBytes($niTx) ?>/s</td>
            <td style="text-align:right"><?= fmtBytes($niRx) ?>/s</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="updated">Last fetched: <?= $fetchedAt ?><?= $refreshSec > 0 ? " &bull; Auto-refresh every {$refreshSec}s" : '' ?></div>

</div><!-- /.container -->
<script>
    function toggleAlerts() {
        const body = document.getElementById('alertPanelBody');
        const icon = document.getElementById('alertToggleIcon');
        const hidden = body.style.display === 'none';
        body.style.display = hidden ? '' : 'none';
        icon.textContent = hidden ? '▲ collapse' : '▼ expand';
    }
</script>
</body>
</html>
