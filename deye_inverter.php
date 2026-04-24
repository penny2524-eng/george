<?php
declare(strict_types=1);

// ── Deye Inverter API (Solarman Cloud) ───────────────────────────────────────
//
// Deye inverters use the Solarman cloud platform.
// Global API base: https://globalapi.solarmanpv.com
// China API base:  https://api.solarmanpv.com
//
// Required credentials (stored in config or .env):
//   APP_ID      – your Solarman developer app ID
//   APP_SECRET  – your Solarman app secret
//   USER_EMAIL  – Solarman account email
//   USER_PASS   – Solarman account password  (plain text sent over HTTPS)
//   DEVICE_SN   – inverter / data-logger serial number (optional: auto-detected)

// ── Config ────────────────────────────────────────────────────────────────────

$CONFIG = [
    'base_url'   => 'https://globalapi.solarmanpv.com',
    'app_id'     => $_ENV['DEYE_APP_ID']     ?? '',
    'app_secret' => $_ENV['DEYE_APP_SECRET'] ?? '',
    'email'      => $_ENV['DEYE_EMAIL']      ?? '',
    'password'   => $_ENV['DEYE_PASSWORD']   ?? '',
    'device_sn'  => $_ENV['DEYE_DEVICE_SN']  ?? '',
];

// Override from posted form (demo / quick-test usage)
$formConfig = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['app_id'])) {
    $formConfig = [
        'app_id'     => trim($_POST['app_id']     ?? ''),
        'app_secret' => trim($_POST['app_secret'] ?? ''),
        'email'      => trim($_POST['email']      ?? ''),
        'password'   => trim($_POST['password']   ?? ''),
        'device_sn'  => trim($_POST['device_sn']  ?? ''),
    ];
    foreach ($formConfig as $k => $v) {
        if ($v !== '') $CONFIG[$k] = $v;
    }
}

// ── Solarman API Client ───────────────────────────────────────────────────────

class SolarmanClient
{
    private string $baseUrl;
    private string $appId;
    private string $appSecret;
    private string $accessToken = '';

    public function __construct(string $baseUrl, string $appId, string $appSecret)
    {
        $this->baseUrl   = rtrim($baseUrl, '/');
        $this->appId     = $appId;
        $this->appSecret = $appSecret;
    }

    // ── Authentication ────────────────────────────────────────────────────────

    /**
     * Obtain an access token.
     * Sign = MD5(appId + timestamp + appSecret)
     */
    public function login(string $email, string $password): array
    {
        $timestamp = time();
        $sign      = md5($this->appId . $timestamp . $this->appSecret);

        $payload = [
            'appSecret' => $this->appSecret,
            'email'     => $email,
            'password'  => md5($password),       // Solarman expects MD5'd password
        ];

        $result = $this->post(
            '/account/v1.0/token?appId=' . urlencode($this->appId)
                . '&language=en&timestamp=' . $timestamp
                . '&sign=' . $sign,
            $payload,
            authenticated: false
        );

        if (isset($result['access_token'])) {
            $this->accessToken = $result['access_token'];
        }

        return $result;
    }

    // ── Stations ──────────────────────────────────────────────────────────────

    /** List all plants / stations linked to the account. */
    public function listStations(int $page = 1, int $size = 20): array
    {
        return $this->post('/station/v1.0/list', [
            'page' => $page,
            'size' => $size,
        ]);
    }

    /** Get detailed info for a single station. */
    public function getStation(int $stationId): array
    {
        return $this->post('/station/v1.0/detail', ['stationId' => $stationId]);
    }

    // ── Devices ───────────────────────────────────────────────────────────────

    /** List devices (loggers/inverters) attached to a station. */
    public function listDevices(int $stationId, int $page = 1, int $size = 20): array
    {
        return $this->post('/station/v1.0/device', [
            'stationId' => $stationId,
            'page'      => $page,
            'size'      => $size,
        ]);
    }

    // ── Real-time data ────────────────────────────────────────────────────────

    /** Fetch latest real-time data points from an inverter by device SN. */
    public function getRealtimeData(string $deviceSn): array
    {
        return $this->post('/device/v1.0/currentData', ['deviceSn' => $deviceSn]);
    }

    /** Fetch historical data for a given day. */
    public function getHistoricalData(string $deviceSn, string $date): array
    {
        return $this->post('/device/v1.0/historical/day', [
            'deviceSn' => $deviceSn,
            'date'     => $date,          // YYYY-MM-DD
        ]);
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────────

    private function post(string $path, array $body, bool $authenticated = true): array
    {
        $url     = $this->baseUrl . $path;
        $headers = ['Content-Type: application/json'];
        if ($authenticated && $this->accessToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            return ['success' => false, 'msg' => 'cURL error: ' . $err];
        }

        $data = json_decode($raw, associative: true);
        if (!is_array($data)) {
            return ['success' => false, 'msg' => 'Invalid JSON response (HTTP ' . $code . ')'];
        }

        return $data;
    }

    public function getAccessToken(): string { return $this->accessToken; }
}

// ── Unit helpers ──────────────────────────────────────────────────────────────

/**
 * Map raw Solarman data-point names to human-readable labels and units.
 * Keys match the `key` field returned in the dataList array.
 */
const METRIC_META = [
    'DV1'  => ['label' => 'PV1 Voltage',        'unit' => 'V',   'icon' => '⚡'],
    'DC1'  => ['label' => 'PV1 Current',         'unit' => 'A',   'icon' => '🔋'],
    'DP1'  => ['label' => 'PV1 Power',           'unit' => 'W',   'icon' => '☀️'],
    'DV2'  => ['label' => 'PV2 Voltage',         'unit' => 'V',   'icon' => '⚡'],
    'DC2'  => ['label' => 'PV2 Current',         'unit' => 'A',   'icon' => '🔋'],
    'DP2'  => ['label' => 'PV2 Power',           'unit' => 'W',   'icon' => '☀️'],
    'SV1'  => ['label' => 'Grid L1 Voltage',     'unit' => 'V',   'icon' => '🏠'],
    'SC1'  => ['label' => 'Grid L1 Current',     'unit' => 'A',   'icon' => '🏠'],
    'SV2'  => ['label' => 'Grid L2 Voltage',     'unit' => 'V',   'icon' => '🏠'],
    'SC2'  => ['label' => 'Grid L2 Current',     'unit' => 'A',   'icon' => '🏠'],
    'SV3'  => ['label' => 'Grid L3 Voltage',     'unit' => 'V',   'icon' => '🏠'],
    'SC3'  => ['label' => 'Grid L3 Current',     'unit' => 'A',   'icon' => '🏠'],
    'APo'  => ['label' => 'Active Power',        'unit' => 'W',   'icon' => '⚡'],
    'RPo'  => ['label' => 'Reactive Power',      'unit' => 'Var', 'icon' => '⚡'],
    'APPo' => ['label' => 'Apparent Power',      'unit' => 'VA',  'icon' => '⚡'],
    'PF'   => ['label' => 'Power Factor',        'unit' => '',    'icon' => '📊'],
    'Fac'  => ['label' => 'Grid Frequency',      'unit' => 'Hz',  'icon' => '📡'],
    'Etdy' => ['label' => 'Energy Today',        'unit' => 'kWh', 'icon' => '📈'],
    'Etot' => ['label' => 'Total Energy',        'unit' => 'kWh', 'icon' => '📊'],
    'Tmp'  => ['label' => 'Inverter Temp.',      'unit' => '°C',  'icon' => '🌡️'],
    'BV'   => ['label' => 'Battery Voltage',     'unit' => 'V',   'icon' => '🔋'],
    'BC'   => ['label' => 'Battery Current',     'unit' => 'A',   'icon' => '🔋'],
    'BP'   => ['label' => 'Battery Power',       'unit' => 'W',   'icon' => '🔋'],
    'BSOC' => ['label' => 'Battery State (SoC)', 'unit' => '%',   'icon' => '🔋'],
    'BST'  => ['label' => 'Battery Status',      'unit' => '',    'icon' => '🔋'],
    'LV'   => ['label' => 'Load Voltage',        'unit' => 'V',   'icon' => '🏭'],
    'LC'   => ['label' => 'Load Current',        'unit' => 'A',   'icon' => '🏭'],
    'LP'   => ['label' => 'Load Power',          'unit' => 'W',   'icon' => '🏭'],
];

function metaFor(string $key): array
{
    return METRIC_META[$key] ?? ['label' => $key, 'unit' => '', 'icon' => '📌'];
}

// ── Run the API calls ─────────────────────────────────────────────────────────

$result   = null;
$error    = null;
$stations = [];
$devices  = [];
$realtimeData = [];

$credentialsProvided =
    $CONFIG['app_id'] !== '' &&
    $CONFIG['app_secret'] !== '' &&
    $CONFIG['email'] !== '' &&
    $CONFIG['password'] !== '';

if ($credentialsProvided) {
    $client = new SolarmanClient($CONFIG['base_url'], $CONFIG['app_id'], $CONFIG['app_secret']);

    $loginResult = $client->login($CONFIG['email'], $CONFIG['password']);

    if (empty($loginResult['access_token'])) {
        $error = 'Login failed: ' . ($loginResult['msg'] ?? json_encode($loginResult));
    } else {
        // Fetch stations
        $stationsResult = $client->listStations();
        $stations = $stationsResult['stationList'] ?? [];

        // If we have a device SN, fetch real-time data directly
        if ($CONFIG['device_sn'] !== '') {
            $rtResult = $client->getRealtimeData($CONFIG['device_sn']);
            if (isset($rtResult['dataList'])) {
                $realtimeData = $rtResult['dataList'];
                $result = $rtResult;
            } else {
                $error = 'Could not fetch device data: ' . ($rtResult['msg'] ?? json_encode($rtResult));
            }
        } elseif (!empty($stations)) {
            // Auto-detect: grab first station's devices
            $firstStationId = (int) ($stations[0]['id'] ?? 0);
            if ($firstStationId > 0) {
                $devResult = $client->listDevices($firstStationId);
                $devices = $devResult['deviceListItems'] ?? [];
            }
        }
    }
}

// ── Helper ────────────────────────────────────────────────────────────────────

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderMetricCard(array $point): string
{
    $key   = $point['key']   ?? '';
    $value = $point['value'] ?? '—';
    $meta  = metaFor($key);
    $unit  = !empty($point['unit']) ? $point['unit'] : $meta['unit'];
    $label = $meta['label'];
    $icon  = $meta['icon'];

    $displayVal = ($value !== '' && $value !== null) ? $value : '—';
    $displayUnit = ($unit !== '' && $displayVal !== '—') ? '<span class="unit">' . e($unit) . '</span>' : '';

    return <<<HTML
    <div class="metric-card">
        <div class="metric-icon">{$icon}</div>
        <div class="metric-value">{$displayVal}{$displayUnit}</div>
        <div class="metric-label">{$label}</div>
    </div>
    HTML;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deye Inverter Dashboard</title>
    <style>
        :root {
            --blue:    #3b82f6;
            --blue-dk: #1e40af;
            --green:   #10b981;
            --red:     #ef4444;
            --amber:   #f59e0b;
            --gray:    #6b7280;
            --bg:      #0f172a;
            --surface: #1e293b;
            --surface2:#263045;
            --border:  #334155;
            --text:    #e2e8f0;
            --muted:   #94a3b8;
            --radius:  12px;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
        }

        header {
            background: linear-gradient(135deg, #0f2027, #1a3a4a, var(--blue-dk));
            padding: 1.25rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            box-shadow: 0 2px 12px rgba(0,0,0,.4);
            flex-wrap: wrap;
        }
        header h1 { font-size: 1.5rem; font-weight: 700; display: flex; align-items: center; gap: .6rem; }
        .header-sub { font-size: .8rem; color: var(--muted); }

        .container { max-width: 1200px; margin: 0 auto; padding: 2rem 1.5rem; }

        /* Flash */
        .flash { padding: .85rem 1.1rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 500; }
        .flash.error   { background: #450a0a; color: #fca5a5; border: 1px solid #7f1d1d; }
        .flash.success { background: #052e16; color: #6ee7b7; border: 1px solid #065f46; }

        /* Config card */
        .card {
            background: var(--surface);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid var(--border);
        }
        .card h2 { font-size: 1.05rem; font-weight: 600; margin-bottom: 1.1rem; color: var(--blue); }

        .form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 1rem; }
        .form-grid .full { grid-column: 1 / -1; }

        label { display: block; font-size: .8rem; font-weight: 600; color: var(--muted); margin-bottom: .3rem; }
        input[type="text"], input[type="password"], input[type="email"] {
            width: 100%; padding: .6rem .85rem;
            background: var(--surface2); border: 1.5px solid var(--border);
            border-radius: 8px; color: var(--text); font-size: .92rem; font-family: inherit;
            transition: border-color .2s;
        }
        input:focus { outline: none; border-color: var(--blue); }

        .btn {
            display: inline-flex; align-items: center; gap: .4rem;
            padding: .65rem 1.4rem; border: none; border-radius: 8px;
            font-size: .9rem; font-weight: 600; cursor: pointer; font-family: inherit;
            transition: filter .15s, transform .1s;
        }
        .btn:hover  { filter: brightness(1.1); }
        .btn:active { transform: scale(.97); }
        .btn-primary { background: var(--blue);  color: #fff; }
        .btn-ghost   { background: var(--surface2); color: var(--muted); border: 1px solid var(--border); }

        /* Status badge */
        .status-badge {
            display: inline-flex; align-items: center; gap: .4rem;
            padding: .3rem .8rem; border-radius: 999px; font-size: .78rem; font-weight: 700;
        }
        .status-on  { background: #052e16; color: #6ee7b7; border: 1px solid #065f46; }
        .status-off { background: #450a0a; color: #fca5a5; border: 1px solid #7f1d1d; }

        /* Metrics grid */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 1rem;
        }
        .metric-card {
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.1rem 1rem;
            text-align: center;
            transition: transform .15s, border-color .15s;
        }
        .metric-card:hover { transform: translateY(-2px); border-color: var(--blue); }
        .metric-icon  { font-size: 1.6rem; margin-bottom: .4rem; }
        .metric-value { font-size: 1.4rem; font-weight: 700; color: var(--text); }
        .metric-value .unit { font-size: .75rem; font-weight: 400; color: var(--muted); margin-left: .2rem; }
        .metric-label { font-size: .72rem; color: var(--muted); margin-top: .3rem; }

        /* Station list */
        .station-list { display: flex; flex-direction: column; gap: .75rem; }
        .station-item {
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1rem 1.25rem;
            display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .75rem;
        }
        .station-name { font-weight: 600; font-size: 1rem; }
        .station-meta { font-size: .78rem; color: var(--muted); margin-top: .2rem; }

        /* Device list */
        .device-list { display: flex; flex-direction: column; gap: .6rem; margin-top: 1rem; }
        .device-item {
            background: var(--surface);
            border: 1px solid var(--border);
            border-left: 3px solid var(--blue);
            border-radius: 8px;
            padding: .75rem 1rem;
            display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .5rem;
        }
        .device-sn { font-family: monospace; font-size: .85rem; color: var(--amber); }

        /* Section heading */
        .section-title {
            font-size: 1.15rem; font-weight: 700; margin-bottom: 1rem;
            padding-bottom: .5rem; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: .6rem;
        }

        .tag {
            display: inline-block; padding: .15rem .55rem;
            background: var(--surface2); border: 1px solid var(--border);
            border-radius: 6px; font-size: .72rem; color: var(--muted);
        }

        .help-text { font-size: .8rem; color: var(--muted); line-height: 1.55; }
        .help-text a { color: var(--blue); text-decoration: none; }
        .help-text a:hover { text-decoration: underline; }

        .last-updated { font-size: .75rem; color: var(--muted); text-align: right; margin-top: .5rem; }

        @media (max-width: 600px) {
            .metrics-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

<header>
    <div>
        <h1>☀️ Deye Inverter Dashboard</h1>
        <div class="header-sub">Connected via Solarman Cloud API</div>
    </div>
    <?php if ($credentialsProvided && !$error): ?>
    <div>
        <span class="status-badge status-on">● Connected</span>
    </div>
    <?php elseif ($credentialsProvided && $error): ?>
    <div>
        <span class="status-badge status-off">● Error</span>
    </div>
    <?php endif; ?>
</header>

<div class="container">

    <?php if ($error !== null): ?>
    <div class="flash error">⚠️ <?= e($error) ?></div>
    <?php endif; ?>

    <!-- Credentials form -->
    <div class="card">
        <h2>🔑 API Credentials</h2>
        <p class="help-text" style="margin-bottom:1rem;">
            Enter your <strong>Solarman developer</strong> credentials.
            Register at <a href="https://home.solarmanpv.com" target="_blank" rel="noopener">home.solarmanpv.com</a>
            and create an app to obtain <em>App ID</em> and <em>App Secret</em>.
            You can also set these as environment variables:
            <code>DEYE_APP_ID</code>, <code>DEYE_APP_SECRET</code>,
            <code>DEYE_EMAIL</code>, <code>DEYE_PASSWORD</code>, <code>DEYE_DEVICE_SN</code>.
        </p>
        <form method="POST" action="">
            <div class="form-grid">
                <div>
                    <label for="app_id">App ID</label>
                    <input type="text" id="app_id" name="app_id"
                           placeholder="e.g. 202906251498"
                           value="<?= e($CONFIG['app_id']) ?>">
                </div>
                <div>
                    <label for="app_secret">App Secret</label>
                    <input type="password" id="app_secret" name="app_secret"
                           value="<?= e($CONFIG['app_secret']) ?>">
                </div>
                <div>
                    <label for="email">Account Email</label>
                    <input type="email" id="email" name="email"
                           placeholder="you@example.com"
                           value="<?= e($CONFIG['email']) ?>">
                </div>
                <div>
                    <label for="password">Account Password</label>
                    <input type="password" id="password" name="password">
                </div>
                <div>
                    <label for="device_sn">Device Serial Number <span class="tag">optional</span></label>
                    <input type="text" id="device_sn" name="device_sn"
                           placeholder="Leave blank to auto-detect"
                           value="<?= e($CONFIG['device_sn']) ?>">
                </div>
                <div style="align-self:flex-end;">
                    <button type="submit" class="btn btn-primary">🔍 Connect &amp; Fetch</button>
                </div>
            </div>
        </form>
    </div>

    <?php if (!empty($realtimeData)): ?>
    <!-- Real-time metrics -->
    <div class="card">
        <div class="section-title">⚡ Real-time Inverter Data
            <?php if (!empty($result['deviceSn'])): ?>
            <span class="tag">SN: <?= e($result['deviceSn']) ?></span>
            <?php endif; ?>
        </div>
        <div class="metrics-grid">
            <?php foreach ($realtimeData as $point): ?>
            <?= renderMetricCard($point) ?>
            <?php endforeach; ?>
        </div>
        <div class="last-updated">Last updated: <?= date('Y-m-d H:i:s') ?></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($stations)): ?>
    <!-- Station list -->
    <div class="card">
        <div class="section-title">🏭 Stations (<?= count($stations) ?>)</div>
        <div class="station-list">
            <?php foreach ($stations as $station): ?>
            <div class="station-item">
                <div>
                    <div class="station-name"><?= e((string)($station['name'] ?? 'Unnamed Station')) ?></div>
                    <div class="station-meta">
                        ID: <?= e((string)($station['id'] ?? '—')) ?>
                        <?php if (!empty($station['locationAddress'])): ?>
                        &bull; <?= e($station['locationAddress']) ?>
                        <?php endif; ?>
                        <?php if (!empty($station['capacity'])): ?>
                        &bull; Capacity: <?= e((string)$station['capacity']) ?> kWp
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <?php
                    $powerStr = $station['generationPower'] ?? null;
                    if ($powerStr !== null):
                    ?>
                    <span class="status-badge status-on">
                        ⚡ <?= e((string)$powerStr) ?> W
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($devices)): ?>
    <!-- Device list -->
    <div class="card">
        <div class="section-title">🔧 Devices — copy a Serial Number above to fetch live data</div>
        <div class="device-list">
            <?php foreach ($devices as $dev): ?>
            <div class="device-item">
                <div>
                    <div><?= e((string)($dev['deviceName'] ?? $dev['deviceType'] ?? 'Device')) ?></div>
                    <div class="device-sn"><?= e((string)($dev['deviceSn'] ?? '')) ?></div>
                </div>
                <span class="tag"><?= e((string)($dev['deviceType'] ?? 'inverter')) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$credentialsProvided): ?>
    <!-- Getting started -->
    <div class="card">
        <div class="section-title">📖 Getting Started</div>
        <ol style="padding-left:1.25rem; line-height:2; color:var(--muted); font-size:.9rem;">
            <li>Create a developer account at <a href="https://home.solarmanpv.com" style="color:var(--blue);">home.solarmanpv.com</a></li>
            <li>Go to <strong>Developer → App Management</strong> and create a new app</li>
            <li>Copy your <strong>App ID</strong> and <strong>App Secret</strong></li>
            <li>Enter them above along with your Solarman account credentials</li>
            <li>Optionally enter your inverter's serial number (printed on the label) to jump straight to live data</li>
        </ol>
    </div>
    <?php endif; ?>

</div>

</body>
</html>
