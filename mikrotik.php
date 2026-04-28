<?php
declare(strict_types=1);

// ── MikroTik REST API config ─────────────────────────────────────────────────

const MT_HOST = '192.168.50.251';
const MT_USER = 'george';
const MT_PASS = 'Penny2000!';
const MT_BASE = 'http://' . MT_HOST . '/rest';

// Auto-refresh interval in seconds (0 = off)
$refreshSec = (int) ($_GET['refresh'] ?? 10);
if ($refreshSec < 0 || $refreshSec > 300) $refreshSec = 10;

// ── REST API helper ──────────────────────────────────────────────────────────

/**
 * Call the MikroTik REST API.
 * Returns decoded array on success, or ['_error' => string] on failure.
 */
function mt(string $path, string $method = 'GET', array $body = []): array
{
    $ch = curl_init(MT_BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => MT_USER . ':' . MT_PASS,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_CUSTOMREQUEST  => $method,
    ]);
    if ($method === 'POST' && $body) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $err !== '') {
        return ['_error' => 'Connection failed: ' . $err];
    }
    if ($code === 401) {
        return ['_error' => 'Authentication failed (401) — check credentials'];
    }
    if ($code >= 400) {
        return ['_error' => "HTTP $code: " . substr($raw, 0, 200)];
    }

    $data = json_decode($raw, associative: true);
    return is_array($data) ? $data : ['_error' => 'Invalid JSON response'];
}

function mtOk(array $data): bool { return !isset($data['_error']); }
function mtErr(array $data): string { return $data['_error'] ?? 'Unknown error'; }

// ── Fetch all data ───────────────────────────────────────────────────────────

$resource   = mt('/system/resource');
$identity   = mt('/system/identity');
$board      = mt('/system/routerboard');
$ifaces     = mt('/interface');
$ipAddrs    = mt('/ip/address');
$dhcpServer = mt('/ip/dhcp-server');
$dhcpPool   = mt('/ip/pool');
$dhcpLease  = mt('/ip/dhcp-server/lease');
$arp        = mt('/ip/arp');
$routes     = mt('/ip/route');
$log        = mt('/log?limit=30');
$wireless   = mt('/interface/wireless/registration-table');
$health     = mt('/system/health');

// ── Format helpers ───────────────────────────────────────────────────────────

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmtBytes(string|int $bytes): string {
    $b = (int) $bytes;
    if ($b >= 1_073_741_824) return round($b / 1_073_741_824, 2) . ' GB';
    if ($b >= 1_048_576)     return round($b / 1_048_576, 1)     . ' MB';
    if ($b >= 1024)          return round($b / 1024, 1)          . ' KB';
    return $b . ' B';
}

function fmtUptime(string $uptime): string {
    // RouterOS format: "1w2d3h4m5s" or "3h4m5s"
    return $uptime;
}

function cpuColor(int $pct): string {
    if ($pct >= 90) return '#ef4444';
    if ($pct >= 70) return '#f59e0b';
    return '#10b981';
}

function memColor(int $pct): string {
    if ($pct >= 90) return '#ef4444';
    if ($pct >= 75) return '#f59e0b';
    return '#3b82f6';
}

function logLevelColor(string $level): string {
    return match (strtolower($level)) {
        'critical', 'error' => '#ef4444',
        'warning'            => '#f59e0b',
        'info'               => '#3b82f6',
        default              => '#6b7280',
    };
}

// ── Alert engine ─────────────────────────────────────────────────────────────
// Each alert: ['level' => 'critical'|'warning'|'info', 'category' => string, 'msg' => string]
$alerts = [];

function alert(string $level, string $category, string $msg): void {
    global $alerts;
    $alerts[] = ['level' => $level, 'category' => $category, 'msg' => $msg];
}

// ── Derived stats
$cpuLoad  = (int) ($resource['cpu-load']    ?? 0);
$memTotal = (int) ($resource['total-memory'] ?? 0);
$memFree  = (int) ($resource['free-memory']  ?? 0);
$memUsed  = $memTotal - $memFree;
$memPct   = $memTotal > 0 ? (int) round($memUsed / $memTotal * 100) : 0;

$hddTotal = (int) ($resource['total-hdd-space'] ?? 0);
$hddFree  = (int) ($resource['free-hdd-space']  ?? 0);
$hddPct   = $hddTotal > 0 ? (int) round(($hddTotal - $hddFree) / $hddTotal * 100) : 0;

$routerName    = e($identity['name'] ?? MT_HOST);
$rosVersion    = e($resource['version'] ?? '—');
$uptime        = e($resource['uptime']  ?? '—');
$boardModel    = e($board['model']      ?? $resource['board-name'] ?? '—');
$architecture  = e($resource['architecture-name'] ?? '—');
$connectedAt   = date('Y-m-d H:i:s');

// ── Generate alerts ───────────────────────────────────────────────────────────

// Connectivity
if (!mtOk($resource)) {
    alert('critical', 'System', 'Router unreachable: ' . mtErr($resource));
}

// CPU
if ($cpuLoad >= 90) alert('critical', 'CPU',    "CPU load critical: {$cpuLoad}%");
elseif ($cpuLoad >= 70) alert('warning', 'CPU', "CPU load high: {$cpuLoad}%");

// Memory
if ($memPct >= 90) alert('critical', 'Memory',    "Memory usage critical: {$memPct}% ({$memUsed}/{$memTotal} bytes)");
elseif ($memPct >= 75) alert('warning', 'Memory', "Memory usage high: {$memPct}%");

// Storage
if ($hddPct >= 90) alert('critical', 'Storage',    "Disk usage critical: {$hddPct}%");
elseif ($hddPct >= 80) alert('warning', 'Storage', "Disk usage high: {$hddPct}%");

// Temperature
$tempVal = null;
if (mtOk($health)) {
    foreach ((array)$health as $item) {
        if (isset($item['name']) && str_contains(strtolower($item['name']), 'temperature')) {
            $tempVal = (int)($item['value'] ?? 0); break;
        }
    }
    if ($tempVal === null && isset($health['temperature'])) $tempVal = (int)$health['temperature'];
}
if ($tempVal !== null) {
    if ($tempVal >= 75) alert('critical', 'Temperature', "Router temperature critical: {$tempVal}°C");
    elseif ($tempVal >= 60) alert('warning', 'Temperature', "Router temperature high: {$tempVal}°C");
}

// Interfaces: down (not disabled) + TX/RX errors/drops
if (mtOk($ifaces)) {
    foreach ($ifaces as $iface) {
        $name     = $iface['name'] ?? '?';
        $running  = ($iface['running']  ?? 'false') === 'true';
        $disabled = ($iface['disabled'] ?? 'false') === 'true';
        if (!$disabled && !$running) {
            alert('warning', 'Interface', "Interface '{$name}' is DOWN");
        }
        $txErr = (int)($iface['tx-error'] ?? 0);
        $rxErr = (int)($iface['rx-error'] ?? 0);
        $txDrp = (int)($iface['tx-drop']  ?? 0);
        $rxDrp = (int)($iface['rx-drop']  ?? 0);
        if ($txErr > 0) alert('warning', 'Interface', "'{$name}': " . number_format($txErr) . " TX error(s)");
        if ($rxErr > 0) alert('warning', 'Interface', "'{$name}': " . number_format($rxErr) . " RX error(s)");
        if ($txDrp > 0) alert('info',    'Interface', "'{$name}': " . number_format($txDrp) . " TX drop(s)");
        if ($rxDrp > 0) alert('info',    'Interface', "'{$name}': " . number_format($rxDrp) . " RX drop(s)");
    }
}

// DHCP pool capacity (computed later in parsePoolRanges — placeholder, filled below)
// We pre-compute here so alerts appear before the pool section renders
function parsePoolRanges(string $ranges): array
{
    $total = 0; $parts = [];
    foreach (explode(',', $ranges) as $range) {
        $range = trim($range);
        if (str_contains($range, '-')) {
            [$start, $end] = explode('-', $range, 2);
            $count = ip2long(trim($end)) - ip2long(trim($start)) + 1;
            if ($count > 0) { $total += $count; $parts[] = ['start' => trim($start), 'end' => trim($end), 'count' => $count]; }
        } else { $total++; $parts[] = ['start' => $range, 'end' => $range, 'count' => 1]; }
    }
    return ['ranges' => $parts, 'total' => $total];
}

$poolInfo    = [];
$serverPool  = [];
$leasesPerPool = [];

if (mtOk($dhcpPool)) {
    foreach ($dhcpPool as $pool) {
        $name = $pool['name'] ?? ''; $ranges = $pool['ranges'] ?? '';
        if ($name === '' || $ranges === '') continue;
        $parsed = parsePoolRanges($ranges);
        $poolInfo[$name] = ['ranges' => $parsed['ranges'], 'total' => $parsed['total'], 'rawRange' => $ranges];
    }
}
if (mtOk($dhcpServer)) {
    foreach ($dhcpServer as $srv) { $serverPool[$srv['name'] ?? ''] = $srv['address-pool'] ?? ''; }
}
if (mtOk($dhcpLease)) {
    $leasesPerServer = [];
    foreach ($dhcpLease as $lease) {
        if (($lease['status'] ?? '') !== 'bound') continue;
        $srv = $lease['server'] ?? '';
        $leasesPerServer[$srv] = ($leasesPerServer[$srv] ?? 0) + 1;
    }
    foreach ($leasesPerServer as $srv => $cnt) {
        $pool = $serverPool[$srv] ?? $srv;
        $leasesPerPool[$pool] = ($leasesPerPool[$pool] ?? 0) + $cnt;
    }
}
foreach ($poolInfo as $pName => $pInfo) {
    $pTotal = $pInfo['total'];
    $pUsed  = $leasesPerPool[$pName] ?? 0;
    $pPct   = $pTotal > 0 ? round($pUsed / $pTotal * 100) : 0;
    $pFree  = max(0, $pTotal - $pUsed);
    if ($pPct >= 90) alert('critical', 'DHCP Pool', "Pool '{$pName}' is {$pPct}% full — only {$pFree} IP(s) remaining");
    elseif ($pPct >= 75) alert('warning', 'DHCP Pool', "Pool '{$pName}' is {$pPct}% full — {$pFree} IP(s) remaining");
}

// Error/warning log entries (up to 10 most recent)
$logAlerts = [];
if (mtOk($log)) {
    foreach (array_reverse($log) as $entry) {
        $topics = strtolower($entry['topics'] ?? '');
        if (str_contains($topics, 'critical') || str_contains($topics, 'error')) {
            $logAlerts[] = ['level' => 'critical', 'topics' => $entry['topics'] ?? '', 'msg' => $entry['message'] ?? '', 'time' => $entry['time'] ?? ''];
        } elseif (str_contains($topics, 'warning')) {
            $logAlerts[] = ['level' => 'warning', 'topics' => $entry['topics'] ?? '', 'msg' => $entry['message'] ?? '', 'time' => $entry['time'] ?? ''];
        }
        if (count($logAlerts) >= 10) break;
    }
}

// Count by level
$critCount = count(array_filter($alerts, fn($a) => $a['level'] === 'critical'));
$warnCount = count(array_filter($alerts, fn($a) => $a['level'] === 'warning'));
$infoCount = count(array_filter($alerts, fn($a) => $a['level'] === 'info'));
$totalAlerts = $critCount + $warnCount + $infoCount + count($logAlerts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MikroTik Monitor — <?= $routerName ?></title>
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

        /* Header */
        header {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 1rem 1.5rem;
            display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .75rem;
        }
        .header-left { display: flex; align-items: center; gap: .75rem; }
        .router-icon { font-size: 1.8rem; }
        .router-name { font-size: 1.25rem; font-weight: 700; color: #fff; }
        .router-sub  { font-size: .78rem; color: var(--muted); margin-top: .1rem; }
        .header-right { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; }

        .badge {
            display: inline-flex; align-items: center; gap: .35rem;
            padding: .25rem .7rem; border-radius: 999px; font-size: .75rem; font-weight: 600;
        }
        .badge-green  { background: #0d1f0f; color: #3fb950; border: 1px solid #238636; }
        .badge-red    { background: #1f0d0d; color: #f85149; border: 1px solid #6e1a1a; }
        .badge-blue   { background: #0d1626; color: #58a6ff; border: 1px solid #1f6feb; }

        .refresh-form { display: flex; align-items: center; gap: .4rem; font-size: .78rem; color: var(--muted); }
        .refresh-form select {
            background: var(--surface2); border: 1px solid var(--border); color: var(--text);
            border-radius: 6px; padding: .2rem .4rem; font-size: .78rem;
        }

        /* Layout */
        .container { max-width: 1400px; margin: 0 auto; padding: 1.25rem 1.5rem; }
        .grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; }
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; }
        .grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; }
        .col-span-2 { grid-column: span 2; }
        .col-span-full { grid-column: 1 / -1; }

        /* Cards */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1rem 1.25rem;
            margin-bottom: 1rem;
        }
        .card-title {
            font-size: .78rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .06em; color: var(--muted);
            margin-bottom: .85rem; display: flex; align-items: center; gap: .5rem;
        }
        .card-title .dot { width: 7px; height: 7px; border-radius: 50%; background: var(--blue); }

        /* Stat tiles */
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: .75rem; margin-bottom: 1rem; }
        .stat-tile {
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: .9rem 1rem;
        }
        .stat-label { font-size: .72rem; color: var(--muted); margin-bottom: .25rem; }
        .stat-value { font-size: 1.5rem; font-weight: 700; color: #fff; }
        .stat-sub   { font-size: .72rem; color: var(--muted); margin-top: .15rem; }

        /* Progress bar */
        .progress-wrap { margin-top: .4rem; }
        .progress-label { display: flex; justify-content: space-between; font-size: .7rem; color: var(--muted); margin-bottom: .2rem; }
        .progress-bar { height: 6px; background: var(--border); border-radius: 3px; overflow: hidden; }
        .progress-fill { height: 100%; border-radius: 3px; transition: width .3s; }

        /* Tables */
        .tbl { width: 100%; border-collapse: collapse; font-size: .82rem; }
        .tbl th { text-align: left; padding: .45rem .65rem; color: var(--muted); font-weight: 600; font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; border-bottom: 1px solid var(--border); }
        .tbl td { padding: .5rem .65rem; border-bottom: 1px solid #21272e; vertical-align: middle; }
        .tbl tr:last-child td { border-bottom: none; }
        .tbl tr:hover td { background: var(--surface2); }

        .dot-green  { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: var(--green);  margin-right: .3rem; }
        .dot-red    { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: var(--red);    margin-right: .3rem; }
        .dot-gray   { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: var(--muted); margin-right: .3rem; }
        .mono       { font-family: 'Consolas', 'Courier New', monospace; font-size: .8rem; color: var(--blue); }
        .tag        { display: inline-block; padding: .1rem .45rem; border-radius: 4px; font-size: .68rem; font-weight: 700; background: var(--surface2); border: 1px solid var(--border); color: var(--muted); }
        .tag-green  { background: #0d1f0f; color: #3fb950; border-color: #238636; }
        .tag-red    { background: #1f0d0d; color: #f85149; border-color: #6e1a1a; }
        .tag-blue   { background: #0d1626; color: #58a6ff; border-color: #1f6feb; }
        .tag-purple { background: #1a1030; color: #a371f7; border-color: #6e40c9; }

        /* Log */
        .log-entry { display: flex; gap: .5rem; padding: .3rem 0; border-bottom: 1px solid #1c2333; font-size: .78rem; }
        .log-entry:last-child { border-bottom: none; }
        .log-time  { color: var(--muted); white-space: nowrap; min-width: 130px; }
        .log-topic { min-width: 80px; }
        .log-msg   { color: var(--text); }

        /* Error */
        .error-box { background: #1f0d0d; border: 1px solid #6e1a1a; border-radius: 8px; padding: .65rem 1rem; color: #f85149; font-size: .8rem; margin-bottom: .5rem; }

        /* Alerts panel */
        .alert-panel { border-radius: var(--radius); border: 1px solid var(--border); margin-bottom: 1.25rem; overflow: hidden; }
        .alert-panel-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: .7rem 1.1rem; background: var(--surface2);
            border-bottom: 1px solid var(--border); cursor: pointer; user-select: none;
        }
        .alert-panel-header h3 { font-size: .9rem; font-weight: 700; display: flex; align-items: center; gap: .5rem; }
        .alert-panel-body { background: var(--surface); }
        .alert-row {
            display: flex; align-items: flex-start; gap: .75rem;
            padding: .55rem 1.1rem; border-bottom: 1px solid #1c2333; font-size: .82rem;
        }
        .alert-row:last-child { border-bottom: none; }
        .alert-row.critical { border-left: 3px solid #f85149; }
        .alert-row.warning  { border-left: 3px solid #d29922; }
        .alert-row.info     { border-left: 3px solid #58a6ff; }
        .alert-icon  { font-size: 1rem; margin-top: .05rem; flex-shrink: 0; }
        .alert-cat   { min-width: 100px; font-weight: 600; font-size: .75rem; }
        .alert-cat.critical { color: #f85149; }
        .alert-cat.warning  { color: #d29922; }
        .alert-cat.info     { color: #58a6ff; }
        .alert-msg   { color: var(--text); flex: 1; }
        .alert-time  { color: var(--muted); font-size: .72rem; white-space: nowrap; }
        .alert-count-badge {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 22px; height: 22px; border-radius: 999px; font-size: .72rem; font-weight: 700; padding: 0 .4rem;
        }
        .acb-red    { background: #6e1a1a; color: #f85149; }
        .acb-amber  { background: #7a4f00; color: #d29922; }
        .acb-blue   { background: #0d2040; color: #58a6ff; }
        .acb-green  { background: #0d1f0f; color: #3fb950; }

        .section-gap { margin-bottom: 1.25rem; }

        .updated { font-size: .72rem; color: var(--muted); text-align: right; margin-top: .5rem; }

        @media (max-width: 900px) {
            .grid-2, .grid-3, .grid-4 { grid-template-columns: 1fr; }
            .col-span-2, .col-span-full { grid-column: span 1; }
        }
    </style>
</head>
<body>

<!-- Header -->
<header>
    <div class="header-left">
        <span class="router-icon">🌐</span>
        <div>
            <div class="router-name"><?= $routerName ?></div>
            <div class="router-sub"><?= e(MT_HOST) ?> &bull; RouterOS <?= $rosVersion ?> &bull; <?= $boardModel ?></div>
        </div>
    </div>
    <div class="header-right">
        <?php if (mtOk($resource)): ?>
        <span class="badge badge-green">● Online</span>
        <span class="badge badge-blue">⏱ <?= $uptime ?></span>
        <?php else: ?>
        <span class="badge badge-red">● Unreachable</span>
        <?php endif; ?>
        <?php if ($critCount > 0): ?>
        <span class="badge badge-red">⚠️ <?= $critCount ?> critical</span>
        <?php elseif ($warnCount > 0): ?>
        <span class="badge" style="background:#1f1500;color:#d29922;border:1px solid #7a4f00;">⚠️ <?= $warnCount ?> warning</span>
        <?php elseif ($totalAlerts === 0): ?>
        <span class="badge badge-green">✓ No alerts</span>
        <?php endif; ?>

        <form class="refresh-form" method="GET">
            <span>Refresh:</span>
            <select name="refresh" onchange="this.form.submit()">
                <option value="0"  <?= $refreshSec === 0  ? 'selected' : '' ?>>Off</option>
                <option value="5"  <?= $refreshSec === 5  ? 'selected' : '' ?>>5 s</option>
                <option value="10" <?= $refreshSec === 10 ? 'selected' : '' ?>>10 s</option>
                <option value="30" <?= $refreshSec === 30 ? 'selected' : '' ?>>30 s</option>
                <option value="60" <?= $refreshSec === 60 ? 'selected' : '' ?>>60 s</option>
            </select>
        </form>
    </div>
</header>

<div class="container">

<?php if (!mtOk($resource)): ?>
<div class="error-box" style="margin-top:1rem;">
    ⚠️ Cannot reach MikroTik at <?= e(MT_HOST) ?>: <?= e(mtErr($resource)) ?>
    <br><small>Make sure this PHP page is served from a host on the same network as the router, and that the REST API is enabled (IP → Services → www / api-ssl).</small>
</div>
<?php endif; ?>

<!-- ── System Stats ───────────────────────────────────────────────────────── -->
<div class="stat-grid">

    <!-- CPU -->
    <div class="stat-tile">
        <div class="stat-label">CPU Load</div>
        <div class="stat-value" style="color:<?= cpuColor($cpuLoad) ?>"><?= $cpuLoad ?>%</div>
        <div class="progress-wrap">
            <div class="progress-bar">
                <div class="progress-fill" style="width:<?= $cpuLoad ?>%;background:<?= cpuColor($cpuLoad) ?>"></div>
            </div>
        </div>
        <div class="stat-sub"><?= e($resource['cpu'] ?? '—') ?> &bull; <?= e($resource['cpu-count'] ?? '1') ?> core(s)</div>
    </div>

    <!-- Memory -->
    <div class="stat-tile">
        <div class="stat-label">Memory</div>
        <div class="stat-value" style="color:<?= memColor($memPct) ?>"><?= $memPct ?>%</div>
        <div class="progress-wrap">
            <div class="progress-label">
                <span><?= fmtBytes($memUsed) ?> used</span>
                <span><?= fmtBytes($memTotal) ?> total</span>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" style="width:<?= $memPct ?>%;background:<?= memColor($memPct) ?>"></div>
            </div>
        </div>
    </div>

    <!-- Storage -->
    <div class="stat-tile">
        <div class="stat-label">Storage</div>
        <div class="stat-value"><?= $hddPct ?>%</div>
        <div class="progress-wrap">
            <div class="progress-label">
                <span><?= fmtBytes($hddTotal - $hddFree) ?> used</span>
                <span><?= fmtBytes($hddTotal) ?> total</span>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" style="width:<?= $hddPct ?>%;background:#58a6ff"></div>
            </div>
        </div>
    </div>

    <!-- Architecture -->
    <div class="stat-tile">
        <div class="stat-label">Platform</div>
        <div class="stat-value" style="font-size:1.1rem;padding-top:.2rem;"><?= $architecture ?></div>
        <div class="stat-sub">
            <?php if (mtOk($board)): ?>
            Serial: <span class="mono" style="font-size:.72rem"><?= e($board['serial-number'] ?? '—') ?></span>
            <?php else: ?>
            —
            <?php endif; ?>
        </div>
    </div>

    <!-- Temperature (if health data available) -->
    <?php
    $temp = null;
    if (mtOk($health)) {
        foreach ((array)$health as $item) {
            if (isset($item['name']) && str_contains(strtolower($item['name']), 'temperature')) {
                $temp = $item['value'] ?? null;
                break;
            }
        }
        // flat response format
        if ($temp === null && isset($health['temperature'])) $temp = $health['temperature'];
    }
    if ($temp !== null): ?>
    <div class="stat-tile">
        <div class="stat-label">Temperature</div>
        <div class="stat-value" style="color:<?= (int)$temp > 70 ? '#ef4444' : '#3fb950' ?>"><?= e((string)$temp) ?>°C</div>
    </div>
    <?php endif; ?>

    <!-- Active DHCP leases -->
    <div class="stat-tile">
        <div class="stat-label">DHCP Leases</div>
        <?php
        $activeLeases = 0;
        $totalPoolIPs = 0;
        if (mtOk($dhcpLease)) {
            foreach ($dhcpLease as $l) {
                if (($l['status'] ?? '') === 'bound') $activeLeases++;
            }
        }
        foreach ($poolInfo as $pInfo) { $totalPoolIPs += $pInfo['total']; }
        $poolPct = $totalPoolIPs > 0 ? (int) round($activeLeases / $totalPoolIPs * 100) : 0;
        ?>
        <div class="stat-value" style="color:<?= $poolPct >= 90 ? '#f85149' : ($poolPct >= 75 ? '#d29922' : '#3fb950') ?>"><?= $activeLeases ?></div>
        <div class="progress-wrap">
            <div class="progress-label">
                <span><?= $activeLeases ?> / <?= $totalPoolIPs ?> IPs</span>
                <span><?= $poolPct ?>%</span>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" style="width:<?= $poolPct ?>%;background:<?= $poolPct >= 90 ? '#f85149' : ($poolPct >= 75 ? '#d29922' : '#3fb950') ?>"></div>
            </div>
        </div>
    </div>

    <!-- Alert summary tile -->
    <div class="stat-tile" style="<?= $critCount > 0 ? 'border-color:#6e1a1a;background:#1f0d0d;' : ($warnCount > 0 ? 'border-color:#7a4f00;background:#1f1500;' : 'border-color:#238636;background:#0d1f0f;') ?>">
        <div class="stat-label">Alerts</div>
        <div class="stat-value" style="color:<?= $critCount > 0 ? '#f85149' : ($warnCount > 0 ? '#d29922' : '#3fb950') ?>">
            <?= $totalAlerts ?>
        </div>
        <div class="stat-sub">
            <?php if ($totalAlerts === 0): ?>
            ✅ All clear
            <?php else: ?>
            <?= $critCount > 0 ? "{$critCount} critical &bull; " : '' ?><?= $warnCount > 0 ? "{$warnCount} warning" : '' ?><?= $infoCount > 0 ? " &bull; {$infoCount} info" : '' ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- ── Errors & Alerts ───────────────────────────────────────────────────── -->
<div class="alert-panel">
    <div class="alert-panel-header" onclick="toggleAlerts()">
        <h3>
            ⚠️ Errors &amp; Alerts
            <?php if ($critCount > 0): ?>
            <span class="alert-count-badge acb-red"><?= $critCount ?> critical</span>
            <?php endif; ?>
            <?php if ($warnCount > 0): ?>
            <span class="alert-count-badge acb-amber"><?= $warnCount ?> warning</span>
            <?php endif; ?>
            <?php if ($infoCount > 0): ?>
            <span class="alert-count-badge acb-blue"><?= $infoCount ?> info</span>
            <?php endif; ?>
            <?php if ($totalAlerts === 0 && empty($logAlerts)): ?>
            <span class="alert-count-badge acb-green">✓ All clear</span>
            <?php endif; ?>
        </h3>
        <span id="alertToggleIcon" style="color:var(--muted);font-size:.82rem;">▲ collapse</span>
    </div>
    <div class="alert-panel-body" id="alertPanelBody">
        <?php if (empty($alerts) && empty($logAlerts)): ?>
        <div class="alert-row info" style="border-left-color:#3fb950;">
            <span class="alert-icon">✅</span>
            <span class="alert-cat" style="color:#3fb950;">System</span>
            <span class="alert-msg">No errors or warnings detected.</span>
        </div>
        <?php endif; ?>

        <?php foreach ($alerts as $a):
            $icon = match($a['level']) { 'critical' => '🔴', 'warning' => '🟡', default => '🔵' };
        ?>
        <div class="alert-row <?= e($a['level']) ?>">
            <span class="alert-icon"><?= $icon ?></span>
            <span class="alert-cat <?= e($a['level']) ?>"><?= e($a['category']) ?></span>
            <span class="alert-msg"><?= e($a['msg']) ?></span>
        </div>
        <?php endforeach; ?>

        <?php if (!empty($logAlerts)): ?>
        <div style="padding:.4rem 1.1rem .2rem;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);background:var(--surface2);border-top:1px solid var(--border);border-bottom:1px solid var(--border);">
            Log Errors / Warnings (last 10)
        </div>
        <?php foreach ($logAlerts as $la):
            $icon = $la['level'] === 'critical' ? '🔴' : '🟡';
        ?>
        <div class="alert-row <?= e($la['level']) ?>">
            <span class="alert-icon"><?= $icon ?></span>
            <span class="alert-cat <?= e($la['level']) ?>"><?= e($la['topics']) ?></span>
            <span class="alert-msg"><?= e($la['msg']) ?></span>
            <span class="alert-time"><?= e($la['time']) ?></span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ── Interfaces ────────────────────────────────────────────────────────── -->
<div class="card section-gap">
    <div class="card-title"><span class="dot"></span> Network Interfaces</div>
    <?php if (!mtOk($ifaces)): ?>
    <div class="error-box"><?= e(mtErr($ifaces)) ?></div>
    <?php else: ?>
    <table class="tbl">
        <thead>
            <tr>
                <th>Status</th>
                <th>Name</th>
                <th>Type</th>
                <th>MAC Address</th>
                <th style="text-align:right">TX Bytes</th>
                <th style="text-align:right">RX Bytes</th>
                <th style="text-align:right">TX Pkts</th>
                <th style="text-align:right">RX Pkts</th>
                <th style="text-align:right;color:#f85149">TX Err</th>
                <th style="text-align:right;color:#f85149">RX Err</th>
                <th style="text-align:right;color:#d29922">TX Drop</th>
                <th style="text-align:right;color:#d29922">RX Drop</th>
                <th>MTU</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($ifaces as $iface):
            $running  = ($iface['running']  ?? 'false') === 'true';
            $disabled = ($iface['disabled'] ?? 'false') === 'true';
            $dotClass = $disabled ? 'dot-gray' : ($running ? 'dot-green' : 'dot-red');
            $txErr = (int)($iface['tx-error'] ?? 0);
            $rxErr = (int)($iface['rx-error'] ?? 0);
            $txDrp = (int)($iface['tx-drop']  ?? 0);
            $rxDrp = (int)($iface['rx-drop']  ?? 0);
        ?>
        <tr>
            <td><span class="<?= $dotClass ?>"></span><?= $disabled ? '<span class="tag">disabled</span>' : ($running ? '<span class="tag tag-green">up</span>' : '<span class="tag tag-red">down</span>') ?></td>
            <td><strong><?= e($iface['name'] ?? '—') ?></strong></td>
            <td><span class="tag tag-blue"><?= e($iface['type'] ?? '—') ?></span></td>
            <td><span class="mono"><?= e($iface['mac-address'] ?? '—') ?></span></td>
            <td style="text-align:right"><?= fmtBytes($iface['tx-byte'] ?? 0) ?></td>
            <td style="text-align:right"><?= fmtBytes($iface['rx-byte'] ?? 0) ?></td>
            <td style="text-align:right"><?= number_format((int)($iface['tx-packet'] ?? 0)) ?></td>
            <td style="text-align:right"><?= number_format((int)($iface['rx-packet'] ?? 0)) ?></td>
            <td style="text-align:right;<?= $txErr > 0 ? 'color:#f85149;font-weight:700;' : 'color:var(--muted)' ?>"><?= $txErr > 0 ? number_format($txErr) : '—' ?></td>
            <td style="text-align:right;<?= $rxErr > 0 ? 'color:#f85149;font-weight:700;' : 'color:var(--muted)' ?>"><?= $rxErr > 0 ? number_format($rxErr) : '—' ?></td>
            <td style="text-align:right;<?= $txDrp > 0 ? 'color:#d29922;font-weight:700;' : 'color:var(--muted)' ?>"><?= $txDrp > 0 ? number_format($txDrp) : '—' ?></td>
            <td style="text-align:right;<?= $rxDrp > 0 ? 'color:#d29922;font-weight:700;' : 'color:var(--muted)' ?>"><?= $rxDrp > 0 ? number_format($rxDrp) : '—' ?></td>
            <td><?= e((string)($iface['mtu'] ?? '—')) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- ── IP Addresses ──────────────────────────────────────────────────────── -->
<div class="grid-2 section-gap">
<div class="card">
    <div class="card-title"><span class="dot"></span> IP Addresses</div>
    <?php if (!mtOk($ipAddrs)): ?>
    <div class="error-box"><?= e(mtErr($ipAddrs)) ?></div>
    <?php else: ?>
    <table class="tbl">
        <thead><tr><th>Address / Prefix</th><th>Interface</th><th>Network</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($ipAddrs as $addr):
            $dis = ($addr['disabled'] ?? 'false') === 'true';
            $dyn = ($addr['dynamic'] ?? 'false') === 'true';
        ?>
        <tr>
            <td><span class="mono"><?= e($addr['address'] ?? '—') ?></span></td>
            <td><?= e($addr['interface'] ?? '—') ?></td>
            <td><span class="mono"><?= e($addr['network'] ?? '—') ?></span></td>
            <td>
                <?php if ($dis): ?>
                <span class="tag tag-red">disabled</span>
                <?php elseif ($dyn): ?>
                <span class="tag tag-blue">dynamic</span>
                <?php else: ?>
                <span class="tag tag-green">static</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- ── Routes ────────────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-title"><span class="dot"></span> Routes</div>
    <?php if (!mtOk($routes)): ?>
    <div class="error-box"><?= e(mtErr($routes)) ?></div>
    <?php else:
        // Show only active routes
        $activeRoutes = array_filter($routes, fn($r) => ($r['active'] ?? 'false') === 'true');
    ?>
    <table class="tbl">
        <thead><tr><th>Destination</th><th>Gateway</th><th>Distance</th><th>Type</th></tr></thead>
        <tbody>
        <?php foreach ($activeRoutes as $route):
            $dyn = ($route['dynamic'] ?? 'false') === 'true';
            $con = ($route['connect'] ?? 'false') === 'true';
            $type = $con ? 'connected' : ($dyn ? 'dynamic' : 'static');
        ?>
        <tr>
            <td><span class="mono"><?= e($route['dst-address'] ?? '—') ?></span></td>
            <td><span class="mono"><?= e($route['gateway'] ?? 'direct') ?></span></td>
            <td><?= e((string)($route['distance'] ?? '—')) ?></td>
            <td>
                <span class="tag <?= $con ? 'tag-green' : ($dyn ? 'tag-blue' : '') ?>"><?= $type ?></span>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
</div>

<!-- ── DHCP Pool Capacity ─────────────────────────────────────────────────── -->
<div class="card section-gap">
    <div class="card-title"><span class="dot"></span> DHCP Pool Capacity</div>
    <?php if (empty($poolInfo)): ?>
    <div class="error-box">No DHCP pools found<?= !mtOk($dhcpPool) ? ': ' . e(mtErr($dhcpPool)) : '' ?></div>
    <?php else:
        foreach ($poolInfo as $poolName => $info):
            $total  = $info['total'];
            $used   = $leasesPerPool[$poolName] ?? 0;
            $free   = max(0, $total - $used);
            $pct    = $total > 0 ? round($used / $total * 100) : 0;
            $barColor  = $pct >= 90 ? '#f85149' : ($pct >= 75 ? '#d29922' : '#3fb950');
            $status    = $pct >= 90 ? 'CRITICAL' : ($pct >= 75 ? 'WARNING' : 'OK');
            $statusCls = $pct >= 90 ? 'tag-red'  : ($pct >= 75 ? ''        : 'tag-green');
            $linkedServers = array_keys(array_filter($serverPool, fn($p) => $p === $poolName));
    ?>
        <div style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:1rem 1.1rem;margin-bottom:.75rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:.6rem;">
                <div>
                    <span style="font-weight:700;font-size:.95rem;"><?= e($poolName) ?></span>
                    <?php foreach ($linkedServers as $s): ?>
                    <span class="tag tag-blue" style="margin-left:.4rem;">server: <?= e($s) ?></span>
                    <?php endforeach; ?>
                    <div style="font-size:.72rem;color:var(--muted);margin-top:.2rem;font-family:monospace;"><?= e($info['rawRange']) ?></div>
                </div>
                <div style="display:flex;align-items:center;gap:.75rem;">
                    <span class="tag <?= $statusCls ?>" style="font-size:.8rem;padding:.2rem .7rem;"><?= $status ?></span>
                    <span style="font-size:1.6rem;font-weight:700;color:<?= $barColor ?>"><?= $pct ?>%</span>
                </div>
            </div>
            <!-- Progress bar -->
            <div class="progress-bar" style="height:10px;margin-bottom:.5rem;">
                <div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>"></div>
            </div>
            <!-- Stats row -->
            <div style="display:flex;gap:1.5rem;font-size:.8rem;">
                <span>📦 Total: <strong><?= $total ?></strong></span>
                <span style="color:<?= $barColor ?>">🔴 Used: <strong><?= $used ?></strong></span>
                <span style="color:#3fb950">🟢 Free: <strong><?= $free ?></strong></span>
                <?php if (!empty($info['ranges']) && count($info['ranges']) > 1): ?>
                <span style="color:var(--muted)"><?= count($info['ranges']) ?> ranges</span>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; endif; ?>
</div>

<!-- ── DHCP Leases ───────────────────────────────────────────────────────── -->
<div class="card section-gap">
    <div class="card-title"><span class="dot"></span> DHCP Leases</div>
    <?php if (!mtOk($dhcpLease)): ?>
    <div class="error-box"><?= e(mtErr($dhcpLease)) ?></div>
    <?php elseif (empty($dhcpLease)): ?>
    <p style="color:var(--muted);font-size:.82rem;">No leases found.</p>
    <?php else: ?>
    <table class="tbl">
        <thead><tr><th>Hostname</th><th>IP Address</th><th>MAC Address</th><th>Expires After</th><th>Server</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($dhcpLease as $lease):
            $bound = ($lease['status'] ?? '') === 'bound';
        ?>
        <tr>
            <td><?= e($lease['host-name'] ?? '—') ?></td>
            <td><span class="mono"><?= e($lease['address'] ?? '—') ?></span></td>
            <td><span class="mono"><?= e($lease['mac-address'] ?? '—') ?></span></td>
            <td><?= e($lease['expires-after'] ?? '—') ?></td>
            <td><?= e($lease['server'] ?? '—') ?></td>
            <td>
                <?php if ($bound): ?>
                <span class="tag tag-green">bound</span>
                <?php else: ?>
                <span class="tag"><?= e($lease['status'] ?? '—') ?></span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- ── ARP Table ─────────────────────────────────────────────────────────── -->
<div class="card section-gap">
    <div class="card-title"><span class="dot"></span> ARP Table</div>
    <?php if (!mtOk($arp)): ?>
    <div class="error-box"><?= e(mtErr($arp)) ?></div>
    <?php elseif (empty($arp)): ?>
    <p style="color:var(--muted);font-size:.82rem;">No ARP entries.</p>
    <?php else: ?>
    <table class="tbl">
        <thead><tr><th>IP Address</th><th>MAC Address</th><th>Interface</th><th>Type</th></tr></thead>
        <tbody>
        <?php foreach ($arp as $entry):
            $dyn = ($entry['dynamic'] ?? 'false') === 'true';
        ?>
        <tr>
            <td><span class="mono"><?= e($entry['address'] ?? '—') ?></span></td>
            <td><span class="mono"><?= e($entry['mac-address'] ?? '—') ?></span></td>
            <td><?= e($entry['interface'] ?? '—') ?></td>
            <td><span class="tag <?= $dyn ? 'tag-blue' : '' ?>"><?= $dyn ? 'dynamic' : 'static' ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- ── Wireless Clients ──────────────────────────────────────────────────── -->
<?php if (mtOk($wireless) && !empty($wireless)): ?>
<div class="card section-gap">
    <div class="card-title"><span class="dot"></span> Wireless Clients (<?= count($wireless) ?>)</div>
    <table class="tbl">
        <thead><tr><th>MAC Address</th><th>Interface</th><th>Signal</th><th>TX Rate</th><th>RX Rate</th><th>Uptime</th></tr></thead>
        <tbody>
        <?php foreach ($wireless as $client): ?>
        <tr>
            <td><span class="mono"><?= e($client['mac-address'] ?? '—') ?></span></td>
            <td><?= e($client['interface'] ?? '—') ?></td>
            <td><?= e($client['signal-strength'] ?? '—') ?> dBm</td>
            <td><?= e($client['tx-rate'] ?? '—') ?></td>
            <td><?= e($client['rx-rate'] ?? '—') ?></td>
            <td><?= e($client['uptime'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- ── System Log ────────────────────────────────────────────────────────── -->
<div class="card section-gap">
    <div class="card-title"><span class="dot"></span> System Log (last 30 entries)</div>
    <?php if (!mtOk($log)): ?>
    <div class="error-box"><?= e(mtErr($log)) ?></div>
    <?php elseif (empty($log)): ?>
    <p style="color:var(--muted);font-size:.82rem;">No log entries.</p>
    <?php else: ?>
    <div style="max-height:320px;overflow-y:auto;">
    <?php foreach (array_reverse($log) as $entry):
        $level = '';
        $topics = $entry['topics'] ?? '';
        if (str_contains($topics, 'error') || str_contains($topics, 'critical')) $level = 'error';
        elseif (str_contains($topics, 'warning')) $level = 'warning';
        else $level = 'info';
    ?>
    <div class="log-entry">
        <span class="log-time"><?= e($entry['time'] ?? '—') ?></span>
        <span class="log-topic" style="color:<?= logLevelColor($level) ?>"><?= e($topics) ?></span>
        <span class="log-msg"><?= e($entry['message'] ?? '') ?></span>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<div class="updated">Last fetched: <?= $connectedAt ?><?= $refreshSec > 0 ? " &bull; Auto-refresh every {$refreshSec}s" : '' ?></div>

</div><!-- /.container -->
<script>
    function toggleAlerts() {
        const body = document.getElementById('alertPanelBody');
        const icon = document.getElementById('alertToggleIcon');
        const hidden = body.style.display === 'none';
        body.style.display = hidden ? '' : 'none';
        icon.textContent = hidden ? '▲ collapse' : '▼ expand';
    }
    // Auto-expand if there are critical alerts, otherwise start expanded
    // (panel is always visible by default)
</script>
</body>
</html>
