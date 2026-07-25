<?php
declare(strict_types=1);

// ── Credentials (from portal.php) ────────────────────────────────────────────
const DEYE_BASE    = 'https://eu1-developer.deyecloud.com/v1.0';
const DEYE_APPID   = '202604284470012';
const DEYE_SECRET  = 'bd6abff1bb8c315065df6fd5a63286d1';
const DEYE_EMAIL   = 'penny2524@gmail.com';
const DEYE_PASS    = 'Penny2000!';
const DEYE_STATION = 61523693;
const DEYE_SN_HINT = '2507133177';

// ── API helper ────────────────────────────────────────────────────────────────
function apiPost(string $path, array $body, string $token = ''): array
{
    $headers = ['Content-Type: application/json'];
    if ($token !== '') {
        $headers[] = 'Authorization: bearer ' . $token;
    }
    $ch = curl_init(DEYE_BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err !== '') {
        return ['_error' => $err, '_code' => $code];
    }
    $decoded = json_decode($raw ?: '', true);
    return is_array($decoded) ? $decoded : ['_error' => 'bad json', '_code' => $code, '_raw' => substr((string)$raw, 0, 300)];
}

// ── Authenticate ──────────────────────────────────────────────────────────────
$authResp = apiPost('/account/token?appId=' . DEYE_APPID, [
    'appSecret' => DEYE_SECRET,
    'email'     => DEYE_EMAIL,
    'password'  => hash('sha256', DEYE_PASS),
    'companyId' => '0',
]);
$token     = $authResp['accessToken'] ?? ($authResp['data']['accessToken'] ?? '');
$authError = $token === ''
    ? ($authResp['msg'] ?? $authResp['message'] ?? ($authResp['_error'] ?? json_encode($authResp)))
    : null;

// ── Fetch data ────────────────────────────────────────────────────────────────
$stData    = [];   // station/latest response
$deviceSn  = '';
$allPoints = [];   // full dataList
$kv        = [];   // key => float value
$kvFull    = [];   // key => full point [{key,name,value,unit}]
$collectTime = '';
$apiError  = null;

if ($token !== '') {
    // Station summary
    $stResp = apiPost('/station/latest', ['stationId' => DEYE_STATION], $token);
    if (!empty($stResp['success'])) {
        $stData = $stResp;
    } else {
        $apiError = 'Station data: ' . ($stResp['msg'] ?? $stResp['_error'] ?? json_encode($stResp));
    }

    // Device list → get inverter SN
    $devResp = apiPost('/station/device', ['stationIds' => [DEYE_STATION]], $token);
    $devList = $devResp['deviceListItems'] ?? ($devResp['deviceList'] ?? []);
    foreach ($devList as $d) {
        if (($d['deviceType'] ?? '') === 'INVERTER') {
            $deviceSn = $d['deviceSn'] ?? '';
            break;
        }
    }
    if ($deviceSn === '') {
        $deviceSn = DEYE_SN_HINT;
    }

    // Device real-time data
    $ltResp  = apiPost('/device/latest', ['deviceList' => [$deviceSn]], $token);
    $devItem = $ltResp['deviceDataList'][0]
            ?? $ltResp['deviceList'][0]
            ?? [];
    $allPoints   = $devItem['dataList'] ?? [];
    $collectTime = $devItem['collectTime'] ?? ($ltResp['collectTime'] ?? '');

    foreach ($allPoints as $p) {
        $key = $p['key'] ?? '';
        if ($key === '') continue;
        $kv[$key]     = isset($p['value']) ? (float) $p['value'] : null;
        $kvFull[$key] = $p;
    }
}

// ── Battery key resolver ──────────────────────────────────────────────────────
// Try multiple known naming conventions, return first match found
function bval(array $kv, array $keys): mixed
{
    foreach ($keys as $k) {
        if (array_key_exists($k, $kv) && $kv[$k] !== null) {
            return $kv[$k];
        }
    }
    return null;
}

function bfull(array $kvFull, array $keys): ?array
{
    foreach ($keys as $k) {
        if (isset($kvFull[$k])) {
            return $kvFull[$k];
        }
    }
    return null;
}

// Battery 1 — try every known key pattern for this metric
$bat1 = [
    'soc'    => bval($kv, ['Battery1SOC','Bat1SOC','BMS_BatterySOC1','BMS_BattSOC1','BattSOC1',
                            'BatterySOC','BatSOC','BMS_BatterySOC','SoC_of_battery_1']),
    'soh'    => bval($kv, ['Battery1SOH','Bat1SOH','BMS_BatteryHealth1','BMS_BattHealth1',
                            'BatteryHealth','BatterySOH','BatSOH','BMS_BatteryCapacity1']),
    'volt'   => bval($kv, ['Battery1Volt','Battery1Voltage','Bat1Volt','BMS_BatteryVoltage1',
                            'BMS_BattVolt1','BatteryVoltage','BatVolt','BattVolt']),
    'curr'   => bval($kv, ['Battery1Current','Battery1Curr','Bat1Current','BMS_BatteryCurrent1',
                            'BMS_BattCurr1','BatteryCurrent','BatCurrent','BattCurr']),
    'power'  => bval($kv, ['Battery1Power','Bat1Power','Battery1Pwr','BMS_BattPower1',
                            'BatteryPower','BatPower','BattPower']),
    'temp'   => bval($kv, ['Battery1Temp','Battery1Temperature','Bat1Temp','BMS_BatteryTemp1',
                            'BMS_BattTemp1','Temperature- Battery','BatteryTemp','BatTemp','BattTemp']),
    'cycles' => bval($kv, ['Battery1Cycles','Bat1Cycles','BMS_BatteryChargeCycles1',
                            'BMS_BattCycles1','BatteryCycles','BatCycles','BattCycles','ChargeCycles1']),
    'status' => bval($kv, ['Battery1Status','Bat1Status','BMS_ChargeState1','BMS_BattStatus1',
                            'BatteryStatus','BatStatus','BattStatus','BMS_BatteryStatus1']),
    'cap'    => bval($kv, ['Battery1CapRemain','Bat1CapRemain','BMS_BatteryCapacityRemain1',
                            'BatteryCapacityRemain','BatCapRemain']),
    'maxv'   => bval($kv, ['Battery1MaxVolt','BMS_BattCellMaxVolt1','BattCellMaxVolt','CellMaxVolt']),
    'minv'   => bval($kv, ['Battery1MinVolt','BMS_BattCellMinVolt1','BattCellMinVolt','CellMinVolt']),
    'alarm'  => bval($kv, ['Battery1Alarm','BMS_BattAlarm1','BatteryAlarm','BatAlarm']),
];

// Battery 2
$bat2 = [
    'soc'    => bval($kv, ['Battery2SOC','Bat2SOC','BMS_BatterySOC2','BMS_BattSOC2','BattSOC2',
                            'SoC_of_battery_2']),
    'soh'    => bval($kv, ['Battery2SOH','Bat2SOH','BMS_BatteryHealth2','BMS_BattHealth2',
                            'BMS_BatteryCapacity2']),
    'volt'   => bval($kv, ['Battery2Volt','Battery2Voltage','Bat2Volt','BMS_BatteryVoltage2',
                            'BMS_BattVolt2']),
    'curr'   => bval($kv, ['Battery2Current','Battery2Curr','Bat2Current','BMS_BatteryCurrent2',
                            'BMS_BattCurr2']),
    'power'  => bval($kv, ['Battery2Power','Bat2Power','Battery2Pwr','BMS_BattPower2']),
    'temp'   => bval($kv, ['Battery2Temp','Battery2Temperature','Bat2Temp','BMS_BatteryTemp2',
                            'BMS_BattTemp2']),
    'cycles' => bval($kv, ['Battery2Cycles','Bat2Cycles','BMS_BatteryChargeCycles2',
                            'BMS_BattCycles2','ChargeCycles2']),
    'status' => bval($kv, ['Battery2Status','Bat2Status','BMS_ChargeState2','BMS_BattStatus2',
                            'BMS_BatteryStatus2']),
    'cap'    => bval($kv, ['Battery2CapRemain','Bat2CapRemain','BMS_BatteryCapacityRemain2']),
    'maxv'   => bval($kv, ['Battery2MaxVolt','BMS_BattCellMaxVolt2','BattCellMaxVolt2']),
    'minv'   => bval($kv, ['Battery2MinVolt','BMS_BattCellMinVolt2','BattCellMinVolt2']),
    'alarm'  => bval($kv, ['Battery2Alarm','BMS_BattAlarm2']),
];

// If bat2 has no dedicated data, it might be reported as battery 1 / combined
$hasBat2 = array_filter($bat2, fn($v) => $v !== null) !== [];

// Station-level (from /station/latest)
$stBatSoc   = $stData['batterySOC']   ?? null;
$stBatPower = $stData['batteryPower'] ?? null;  // negative = charging
$stGenPower = $stData['generationPower']   ?? null;
$stLoadPower= $stData['consumptionPower']  ?? null;
$stGridPower= $stData['wirePower']         ?? null;
$stDayKwh   = $stData['generationValue']   ?? null;
$stTotKwh   = $stData['totalGenerationValue'] ?? null;
$stLastUp   = $stData['lastUpdateTime']    ?? null;

// SOC: fall back to station level if device-level not found
if ($bat1['soc'] === null && $stBatSoc !== null) {
    $bat1['soc'] = $stBatSoc;
}
if ($bat1['power'] === null && $stBatPower !== null) {
    $bat1['power'] = $stBatPower;
}

// ── Categorise all data points ────────────────────────────────────────────────
// Group every point from dataList into named sections for display
$sections = [
    'bat_combined' => [],
    'bat1'         => [],
    'bat2'         => [],
    'pv'           => [],
    'grid'         => [],
    'load'         => [],
    'inverter'     => [],
    'other'        => [],
];

foreach ($allPoints as $p) {
    $key  = strtolower($p['key']  ?? '');
    $name = strtolower($p['name'] ?? '');
    $combined = $key . ' ' . $name;

    // Detect battery number
    $isBat1  = preg_match('/bat1|battery1|batt1|bms.*1|_1$|no\.?1|num\.?1/i', $combined);
    $isBat2  = preg_match('/bat2|battery2|batt2|bms.*2|_2$|no\.?2|num\.?2/i', $combined);
    $isBat   = preg_match('/bat|batt|battery|bms|soc|cell|lithium|pack/i', $combined);
    $isPv    = preg_match('/pv|solar|dc.volt|dc.curr|dc.power|string/i', $combined);
    $isGrid  = preg_match('/grid|wire|meter|import|export|ac.volt|ac.curr|frequency|hz/i', $combined);
    $isLoad  = preg_match('/load|consump|home|house/i', $combined);
    $isInv   = preg_match('/temp|inverter|status|fault|warn|alarm|power.?factor|apparent|reactive/i', $combined);

    if ($isBat1) {
        $sections['bat1'][] = $p;
    } elseif ($isBat2) {
        $sections['bat2'][] = $p;
    } elseif ($isBat) {
        $sections['bat_combined'][] = $p;
    } elseif ($isPv) {
        $sections['pv'][] = $p;
    } elseif ($isGrid) {
        $sections['grid'][] = $p;
    } elseif ($isLoad) {
        $sections['load'][] = $p;
    } elseif ($isInv) {
        $sections['inverter'][] = $p;
    } else {
        $sections['other'][] = $p;
    }
}

// ── Helper ────────────────────────────────────────────────────────────────────
function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmtVal(mixed $v, string $unit = '', int $dp = 1): string
{
    if ($v === null) return '—';
    $n = round((float) $v, $dp);
    return $n . ($unit !== '' ? ' ' . $unit : '');
}

function batStatus(mixed $v): string
{
    if ($v === null) return '—';
    $s = (int) $v;
    return match($s) {
        0  => 'Standby',
        1  => 'Charging',
        2  => 'Discharging',
        3  => 'Fault',
        4  => 'Hibernating',
        11 => 'Idle',
        default => 'State ' . $s,
    };
}

function powerLabel(mixed $w): array  // [label, arrow, css-class]
{
    if ($w === null) return ['—', '', 'muted'];
    $f = (float) $w;
    if ($f < -20) return ['Charging',     '↑', 'charging'];
    if ($f >  20) return ['Discharging',  '↓', 'discharging'];
    return ['Idle', '◉', 'idle'];
}

function socColor(mixed $soc): string
{
    if ($soc === null) return '#4a6878';
    $s = (float) $soc;
    if ($s >= 50) return '#00ffcc';
    if ($s >= 20) return '#ffcc00';
    return '#ff4070';
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Deye Battery Dashboard</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:      #182d4e;
  --surface: rgba(35, 82, 150, 0.70);
  --card:    rgba(12, 28, 55, 0.85);
  --border:  rgba(0, 212, 255, 0.30);
  --border-h:rgba(0, 212, 255, 0.75);
  --text:    #f0f8ff;
  --muted:   #92b8d8;
  --green:   #00ffcc;
  --red:     #ff4070;
  --yellow:  #ffcc00;
  --blue:    #38c8ff;
  --cyan:    #00f5ff;
  --purple:  #c084fc;
  --amber:   #fbbf24;
  --radius:  12px;
}

body {
  font-family: 'Segoe UI', system-ui, sans-serif;
  background-color: var(--bg);
  background-image:
    linear-gradient(rgba(0, 212, 255, 0.09) 1px, transparent 1px),
    linear-gradient(90deg, rgba(0, 212, 255, 0.09) 1px, transparent 1px);
  background-size: 44px 44px;
  color: var(--text);
  min-height: 100vh;
}

/* Orbs */
.orb { position:fixed; border-radius:50%; filter:blur(70px); pointer-events:none; z-index:-1; }
.orb-1 { width:620px;height:620px;top:-120px;left:-100px;background:radial-gradient(circle,rgba(30,100,255,.38) 0%,transparent 68%);animation:od1 16s ease-in-out infinite alternate; }
.orb-2 { width:500px;height:500px;bottom:-100px;right:-80px;background:radial-gradient(circle,rgba(160,0,255,.32) 0%,transparent 68%);animation:od2 20s ease-in-out infinite alternate; }
.orb-3 { width:420px;height:420px;top:30%;right:-60px;background:radial-gradient(circle,rgba(0,220,200,.24) 0%,transparent 68%);animation:od3 13s ease-in-out infinite alternate; }
@keyframes od1 { from{transform:translate(0,0) scale(1)} to{transform:translate(80px,100px) scale(1.16)} }
@keyframes od2 { from{transform:translate(0,0) scale(1)} to{transform:translate(-80px,-80px) scale(1.2)} }
@keyframes od3 { from{transform:translate(0,0) scale(1)} to{transform:translate(-60px,80px) scale(1.1)} }

/* Header */
header {
  background: linear-gradient(135deg, #0a1628 0%, #0f2240 50%, #162e52 100%);
  border-bottom: 1px solid var(--border);
  padding: .8rem 2rem;
  display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .75rem;
  position: sticky; top: 0; z-index: 10;
  backdrop-filter: blur(12px);
}
header h1 { font-size: 1.3rem; font-weight: 800; display:flex; align-items:center; gap:.6rem; }
.grad {
  background: linear-gradient(120deg, var(--amber) 0%, #ff9500 35%, var(--cyan) 70%, var(--purple) 100%);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
  background-clip: text;
  filter: drop-shadow(0 0 18px rgba(255,190,0,.6));
}
.header-right { display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; }
.refresh-btn {
  background: rgba(0,212,255,.07); border:1px solid rgba(0,212,255,.3);
  color:var(--cyan); border-radius:7px; padding:.3rem .75rem; font-size:.78rem;
  cursor:pointer; transition:all .2s; font-family:'Courier New',monospace;
}
.refresh-btn:hover { background:rgba(0,212,255,.14); border-color:var(--cyan); box-shadow:0 0 18px rgba(0,212,255,.3); }
.update-time { font-size:.7rem; color:var(--muted); font-family:'Courier New',monospace; }

/* Badges */
.badge {
  display:inline-flex; align-items:center; gap:.3rem; border-radius:20px;
  padding:.18rem .6rem; font-size:.67rem; font-weight:700;
  text-transform:uppercase; letter-spacing:.07em; font-family:'Courier New',monospace;
}
.badge::before { content:''; width:5px; height:5px; border-radius:50%; background:currentColor; flex-shrink:0; }
.badge-ok      { background:rgba(0,255,204,.12); color:var(--green); border:1px solid rgba(0,255,204,.45); }
.badge-ok::before { animation:dotpulse 2s ease-in-out infinite; }
.badge-err     { background:rgba(255,64,112,.1); color:var(--red); border:1px solid rgba(255,64,112,.38); }
.badge-warn    { background:rgba(255,204,0,.1); color:var(--yellow); border:1px solid rgba(255,204,0,.4); }
@keyframes dotpulse { 0%,100%{opacity:1} 50%{opacity:.15} }

/* Main layout */
.main { max-width: 1360px; margin: 0 auto; padding: 1.5rem 1.2rem 3rem; }

/* Error/info banner */
.banner { padding:.7rem 1rem; border-radius:9px; margin-bottom:1.2rem; font-size:.88rem; font-weight:500; }
.banner-err  { background:rgba(255,64,112,.12); color:#ffb3c1; border:1px solid rgba(255,64,112,.38); }
.banner-info { background:rgba(0,212,255,.08); color:var(--muted); border:1px solid rgba(0,212,255,.2); }

/* Section title */
.section-title {
  font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.12em;
  color:var(--muted); font-family:'Courier New',monospace; margin-bottom:.8rem;
  padding-bottom:.4rem; border-bottom:1px solid rgba(0,212,255,.15);
}

/* Power flow overview */
.flow-card {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 1.1rem 1.3rem 1rem;
  margin-bottom: 1.4rem;
  backdrop-filter: blur(18px);
}
.flow-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: .5rem;
  align-items: center;
  margin-top: .6rem;
}
.flow-node {
  text-align: center;
  background: rgba(0,0,0,.35);
  border: 1px solid rgba(0,212,255,.2);
  border-radius: 10px;
  padding: .55rem .4rem;
}
.fn-icon { font-size: 1.3rem; }
.fn-val  { font-size: .95rem; font-weight:700; font-family:'Courier New',monospace; margin:.2rem 0 .1rem; }
.fn-lbl  { font-size: .55rem; text-transform:uppercase; letter-spacing:.08em; color:var(--muted); }
.flow-arrow { text-align:center; font-size:1.2rem; color:rgba(0,212,255,.5); }

/* Battery cards grid */
.bat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
  gap: 1.2rem;
  margin-bottom: 1.4rem;
}

/* Battery card */
.bat-card {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 1.1rem 1.3rem;
  backdrop-filter: blur(18px);
  transition: border-color .25s;
}
.bat-card:hover { border-color: var(--border-h); }

.bat-header {
  display: flex; align-items: center; gap: .75rem; margin-bottom: 1rem;
}
.bat-num {
  width: 32px; height: 32px; border-radius: 9px;
  display:flex; align-items:center; justify-content:center;
  font-size: 1.2rem; flex-shrink: 0;
  background: rgba(192, 132, 252, .2); border:1px solid rgba(192,132,252,.45);
}
.bat-title { font-size: 1rem; font-weight:700; }
.bat-subtitle { font-size: .68rem; color:var(--muted); font-family:'Courier New',monospace; margin-top:.12rem; }

/* SOC display */
.soc-wrap {
  display: flex; align-items: center; gap: 1.2rem; margin-bottom: 1rem;
}
.soc-ring { position:relative; width:88px; height:88px; flex-shrink:0; }
.soc-ring svg { width:100%; height:100%; transform:rotate(-90deg); }
.soc-ring .ring-bg  { fill:none; stroke:rgba(0,212,255,.12); stroke-width:7; }
.soc-ring .ring-val { fill:none; stroke-width:7; stroke-linecap:round; transition:stroke-dashoffset .8s cubic-bezier(.4,0,.2,1); }
.soc-ring-center {
  position:absolute; inset:0; display:flex; flex-direction:column;
  align-items:center; justify-content:center; text-align:center;
}
.soc-pct   { font-size:1.25rem; font-weight:800; font-family:'Courier New',monospace; line-height:1; }
.soc-label { font-size:.55rem; color:var(--muted); text-transform:uppercase; letter-spacing:.08em; margin-top:.1rem; }

.soc-info { flex:1; }
.soc-status {
  display:inline-flex; align-items:center; gap:.35rem;
  font-size:.8rem; font-weight:700; margin-bottom:.5rem;
}
.soc-status .dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
.charging    .dot { background:var(--green); box-shadow:0 0 8px var(--green); animation:dotpulse 1.5s ease-in-out infinite; }
.discharging .dot { background:var(--amber); box-shadow:0 0 8px var(--amber); }
.idle        .dot { background:var(--muted); }

.power-val {
  font-size:1.4rem; font-weight:800; font-family:'Courier New',monospace;
  text-shadow: 0 0 18px currentColor;
}
.power-dir { font-size:.65rem; color:var(--muted); margin-top:.08rem; font-family:'Courier New',monospace; }

/* Progress bars */
.bar-block { margin-bottom: .6rem; }
.bar-label { display:flex; justify-content:space-between; font-size:.65rem; color:var(--muted); margin-bottom:.28rem; font-family:'Courier New',monospace; }
.bar-track { height:5px; background:rgba(0,212,255,.1); border-radius:3px; overflow:hidden; }
.bar-fill  { height:100%; border-radius:3px; transition:width .7s cubic-bezier(.4,0,.2,1); }
.fill-green  { background:linear-gradient(90deg,var(--green),var(--blue)); box-shadow:0 0 8px rgba(0,255,184,.5); }
.fill-yellow { background:linear-gradient(90deg,var(--yellow),#ff8800); box-shadow:0 0 8px rgba(255,204,0,.4); }
.fill-red    { background:linear-gradient(90deg,var(--red),#ff0050); box-shadow:0 0 8px rgba(255,64,112,.5); }
.fill-purple { background:linear-gradient(90deg,var(--purple),#7c3aed); box-shadow:0 0 8px rgba(192,132,252,.4); }

/* Stats grid */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
  gap: .55rem;
  margin-top: .85rem;
}
.stat-cell {
  background: rgba(0,212,255,.04);
  border: 1px solid rgba(0,212,255,.12);
  border-radius: 8px;
  padding: .5rem .55rem;
  text-align: center;
}
.stat-lbl { font-size:.55rem; color:var(--muted); text-transform:uppercase; letter-spacing:.08em; font-family:'Courier New',monospace; }
.stat-val { font-size:.88rem; font-weight:700; margin-top:.25rem; font-family:'Courier New',monospace; text-shadow:0 0 12px rgba(0,238,255,.45); }
.stat-unit { font-size:.58rem; color:var(--muted); margin-left:.1rem; font-weight:400; }
.stat-val.warn { color:var(--yellow); }
.stat-val.crit { color:var(--red); }
.stat-val.good { color:var(--green); }

/* Cycles highlight */
.cycles-highlight {
  background: rgba(192, 132, 252, .1);
  border: 1px solid rgba(192,132,252,.3);
  border-radius: 8px;
  padding: .5rem .7rem;
  display: flex; align-items: center; justify-content: space-between;
  margin-top: .6rem;
}
.cycles-lbl { font-size:.65rem; color:var(--purple); font-family:'Courier New',monospace; text-transform:uppercase; letter-spacing:.08em; }
.cycles-num { font-size:1.2rem; font-weight:800; font-family:'Courier New',monospace; color:var(--purple); text-shadow:0 0 14px rgba(192,132,252,.6); }

/* Data table */
.data-table-wrap { overflow-x:auto; margin-top:.6rem; }
table { width:100%; border-collapse:collapse; font-size:.78rem; }
th, td { padding:.38rem .65rem; text-align:left; border-bottom:1px solid rgba(0,212,255,.09); }
th { font-size:.62rem; text-transform:uppercase; letter-spacing:.08em; color:var(--muted); font-family:'Courier New',monospace; font-weight:600; background:rgba(0,0,0,.25); }
td.key-col { font-family:'Courier New',monospace; font-size:.72rem; color:var(--cyan); }
td.val-col { font-family:'Courier New',monospace; font-weight:700; }
td.unit-col { color:var(--muted); }
tr:hover td { background:rgba(0,212,255,.04); }

/* Collapsible section */
.collapse-card {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  margin-bottom: 1.2rem;
  backdrop-filter: blur(18px);
  overflow: hidden;
}
.collapse-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: .85rem 1.3rem; cursor: pointer; user-select: none;
  transition: background .15s;
}
.collapse-header:hover { background: rgba(0,212,255,.05); }
.collapse-title { font-size:.88rem; font-weight:600; display:flex; align-items:center; gap:.5rem; }
.collapse-arrow { font-size:.75rem; color:var(--muted); transition:transform .25s; }
.collapse-body { padding: 0 1.3rem 1.1rem; }
.collapse-body.hidden { display:none; }
.collapse-arrow.open { transform:rotate(90deg); }

/* Sub-sections */
.sub-section { margin-bottom: 1.2rem; }
.sub-section:last-child { margin-bottom: 0; }
.sub-title {
  font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.1em;
  color:var(--blue); font-family:'Courier New',monospace; margin-bottom:.5rem;
  padding-bottom:.3rem; border-bottom:1px solid rgba(56,200,255,.2);
}

/* No data state */
.no-data { color:var(--muted); font-size:.82rem; font-style:italic; padding:.4rem 0; }

/* Footer */
footer {
  text-align:center; font-size:.7rem; color:var(--muted); font-family:'Courier New',monospace;
  padding:1rem 0 2rem; letter-spacing:.05em;
}

@media (max-width: 640px) {
  .bat-grid { grid-template-columns: 1fr; }
  .flow-grid { grid-template-columns: repeat(3, 1fr); }
  .flow-arrow { display:none; }
  header { padding:.7rem 1rem; }
}
</style>
</head>
<body>

<div class="orb orb-1"></div>
<div class="orb orb-2"></div>
<div class="orb orb-3"></div>

<header>
  <h1>🔋 <span class="grad">Deye Battery Dashboard</span></h1>
  <div class="header-right">
    <?php if ($authError !== null): ?>
      <span class="badge badge-err">Auth Failed</span>
    <?php elseif ($token !== '' && $allPoints !== []): ?>
      <span class="badge badge-ok">Connected</span>
    <?php elseif ($token !== ''): ?>
      <span class="badge badge-warn">No Data</span>
    <?php endif; ?>
    <span class="update-time" id="update-time">
      <?= $collectTime !== '' ? 'Collected: ' . e($collectTime) : ($stLastUp !== null ? 'Updated: ' . e((string)$stLastUp) : 'Loaded: ' . date('H:i:s')) ?>
    </span>
    <button class="refresh-btn" onclick="location.reload()">↺ Refresh</button>
  </div>
</header>

<div class="main">

<?php if ($authError !== null): ?>
<div class="banner banner-err">
  ⚠️ Authentication failed — <?= e((string)$authError) ?><br>
  <small style="opacity:.7">Check API credentials and IP allowlist at developer.deyecloud.com</small>
</div>
<?php endif; ?>

<?php if ($apiError !== null): ?>
<div class="banner banner-err">⚠️ <?= e($apiError) ?></div>
<?php endif; ?>

<?php if ($token !== '' && $allPoints === [] && $apiError === null): ?>
<div class="banner banner-info">
  ℹ️ Authenticated but no device data returned. The inverter may be offline or device SN
  (<?= e(DEYE_SN_HINT) ?>) may need updating.
  <?php if (!empty($ltResp ?? [])): ?>
  Raw response: <code><?= e(substr(json_encode($ltResp ?? []), 0, 200)) ?></code>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Power Flow Overview ──────────────────────────────────────────────────── -->
<div class="flow-card">
  <div class="section-title">⚡ Real-time Power Flow — Station <?= DEYE_STATION ?></div>
  <div class="flow-grid">

    <?php
    $fmtW = function(mixed $w): string {
        if ($w === null) return '—';
        $a = abs((float)$w);
        return $a >= 1000 ? round($a/1000, 2).' kW' : round($a).' W';
    };
    $solarW = max(0, (float)($stGenPower ?? 0));
    $gridW  = (float)($stGridPower  ?? 0);
    $batW   = (float)($stBatPower   ?? $bat1['power'] ?? 0);
    $loadW  = max(0, (float)($stLoadPower  ?? 0));
    $soc    = $bat1['soc'] ?? $stBatSoc ?? null;

    $gridDir  = $gridW > 20  ? 'Importing' : ($gridW < -20 ? 'Exporting' : 'On grid');
    $batDir   = $batW  < -20 ? 'Charging'  : ($batW  > 20  ? 'Discharging' : 'Idle');
    $batCol   = socColor($soc);
    ?>

    <div class="flow-node">
      <div class="fn-icon">☀️</div>
      <div class="fn-val" style="color:var(--amber)"><?= $fmtW($solarW) ?></div>
      <div class="fn-lbl">Solar</div>
    </div>

    <div class="flow-arrow"><?= $solarW > 20 ? '→' : '·' ?></div>

    <div class="flow-node">
      <div class="fn-icon">⚡</div>
      <div class="fn-val" style="color:var(--cyan)">Inverter</div>
      <div class="fn-lbl"><?= $stDayKwh !== null ? round((float)$stDayKwh,1).' kWh today' : 'Station '.DEYE_STATION ?></div>
    </div>

    <div class="flow-arrow"><?= $loadW > 20 ? '→' : '·' ?></div>

    <div class="flow-node">
      <div class="fn-icon">🏠</div>
      <div class="fn-val" style="color:var(--green)"><?= $fmtW($loadW) ?></div>
      <div class="fn-lbl">Load</div>
    </div>

    <!-- Row 2: Grid and Battery below inverter -->
    <div class="flow-node" style="grid-column:1">
      <div class="fn-icon">🔌</div>
      <div class="fn-val" style="color:var(--blue)"><?= $fmtW(abs($gridW)) ?></div>
      <div class="fn-lbl"><?= e($gridDir) ?></div>
    </div>

    <div class="flow-arrow"></div>

    <div class="flow-node" style="background:rgba(192,132,252,.1);border-color:rgba(192,132,252,.35)">
      <div class="fn-icon">🔋</div>
      <div class="fn-val" style="color:<?= $batCol ?>">
        <?= $soc !== null ? round((float)$soc).'%' : '—' ?>
      </div>
      <div class="fn-lbl"><?= e($batDir) ?> · <?= $fmtW(abs($batW)) ?></div>
    </div>

    <div class="flow-arrow"></div>

    <div class="flow-node">
      <div class="fn-icon">📊</div>
      <div class="fn-val" style="color:var(--muted);font-size:.78rem">
        <?= $stTotKwh !== null ? round((float)$stTotKwh).' kWh' : '—' ?>
      </div>
      <div class="fn-lbl">Total energy</div>
    </div>

  </div>
</div>

<!-- ── Dual Battery Cards ────────────────────────────────────────────────────── -->
<div class="section-title">🔋 Battery Status</div>
<div class="bat-grid">

<?php
foreach (['1' => $bat1, '2' => $bat2] as $num => $bat):
    $soc     = $bat['soc'];
    $soh     = $bat['soh'];
    $volt    = $bat['volt'];
    $curr    = $bat['curr'];
    $power   = $bat['power'];
    $temp    = $bat['temp'];
    $cycles  = $bat['cycles'];
    $status  = $bat['status'];
    $cap     = $bat['cap'];
    $maxv    = $bat['maxv'];
    $minv    = $bat['minv'];

    [$pwrLabel, $pwrArrow, $pwrClass] = powerLabel($power);

    $socVal    = $soc !== null ? (int) round((float)$soc) : null;
    $sohVal    = $soh !== null ? (int) round((float)$soh) : null;
    $col       = socColor($soc);

    // SOC ring: circumference of circle r=38 is ~238.76
    $circ      = 238.76;
    $dashOffset = $socVal !== null ? round($circ * (1 - $socVal / 100), 2) : $circ;

    $socBarClass = $socVal === null ? 'fill-green' : ($socVal >= 50 ? 'fill-green' : ($socVal >= 20 ? 'fill-yellow' : 'fill-red'));
    $sohBarClass = $sohVal === null ? 'fill-purple' : ($sohVal >= 80 ? 'fill-purple' : ($sohVal >= 60 ? 'fill-yellow' : 'fill-red'));
    $tempVal   = $temp !== null ? round((float)$temp, 1) : null;
    $tempClass = $tempVal === null ? '' : ($tempVal >= 45 ? 'crit' : ($tempVal >= 35 ? 'warn' : 'good'));

    $hasData   = ($socVal !== null || $volt !== null || $cycles !== null);
    if ($num === '2' && !$hasBat2 && !$hasData):
?>
  <!-- Battery 2: no dedicated data -->
  <div class="bat-card" style="opacity:.5">
    <div class="bat-header">
      <div class="bat-num">🔋</div>
      <div>
        <div class="bat-title">Battery <?= $num ?></div>
        <div class="bat-subtitle">No separate data — may share BMS with Battery 1</div>
      </div>
    </div>
    <p class="no-data">No individual measure points found for battery <?= $num ?>.<br>
    Check the raw data section below for all available keys.</p>
  </div>
<?php else: ?>
  <div class="bat-card">
    <div class="bat-header">
      <div class="bat-num">🔋</div>
      <div>
        <div class="bat-title">Battery <?= $num ?></div>
        <div class="bat-subtitle">
          <?php if ($deviceSn !== ''): ?>SN&nbsp;<code><?= e($deviceSn) ?></code>&nbsp;·&nbsp;<?php endif; ?>
          <?= $status !== null ? e(batStatus($status)) : 'Lithium Ion Pack' ?>
        </div>
      </div>
      <?php if ($status !== null): ?>
      <span class="badge <?= (int)$status === 1 ? 'badge-ok' : ((int)$status === 3 ? 'badge-err' : 'badge-warn') ?>" style="margin-left:auto">
        <?= e(batStatus($status)) ?>
      </span>
      <?php elseif ($pwrClass === 'charging'): ?>
      <span class="badge badge-ok" style="margin-left:auto">Charging</span>
      <?php elseif ($pwrClass === 'discharging'): ?>
      <span class="badge badge-warn" style="margin-left:auto">Discharging</span>
      <?php endif; ?>
    </div>

    <!-- SOC + Power -->
    <div class="soc-wrap">
      <div class="soc-ring">
        <svg viewBox="0 0 88 88">
          <circle class="ring-bg" cx="44" cy="44" r="38"/>
          <circle class="ring-val" cx="44" cy="44" r="38"
            stroke="<?= $col ?>"
            stroke-dasharray="<?= $circ ?>"
            stroke-dashoffset="<?= $dashOffset ?>"/>
        </svg>
        <div class="soc-ring-center">
          <div class="soc-pct" style="color:<?= $col ?>"><?= $socVal !== null ? $socVal.'%' : '—' ?></div>
          <div class="soc-label">SoC</div>
        </div>
      </div>
      <div class="soc-info">
        <div class="soc-status <?= $pwrClass ?>">
          <span class="dot"></span>
          <span><?= $pwrArrow ?> <?= e($pwrLabel) ?></span>
        </div>
        <?php if ($power !== null): ?>
        <div class="power-val" style="color:<?= $pwrClass === 'charging' ? 'var(--green)' : ($pwrClass === 'discharging' ? 'var(--amber)' : 'var(--muted)') ?>">
          <?= abs(round((float)$power)) ?><span style="font-size:.7rem;font-weight:400;color:var(--muted);margin-left:.2rem">W</span>
        </div>
        <div class="power-dir"><?= $pwrClass === 'charging' ? 'Inverter → Battery' : ($pwrClass === 'discharging' ? 'Battery → Inverter' : 'No flow') ?></div>
        <?php else: ?>
        <div class="power-val" style="color:var(--muted)">—</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- SoC bar -->
    <div class="bar-block">
      <div class="bar-label"><span>State of Charge</span><span><?= $socVal !== null ? $socVal.'%' : '—' ?></span></div>
      <div class="bar-track"><div class="bar-fill <?= $socBarClass ?>" style="width:<?= $socVal ?? 0 ?>%"></div></div>
    </div>

    <!-- SoH bar -->
    <?php if ($sohVal !== null): ?>
    <div class="bar-block">
      <div class="bar-label"><span>State of Health (SoH)</span><span><?= $sohVal ?>%</span></div>
      <div class="bar-track"><div class="bar-fill <?= $sohBarClass ?>" style="width:<?= $sohVal ?>%"></div></div>
    </div>
    <?php endif; ?>

    <!-- Temperature bar -->
    <?php if ($tempVal !== null): ?>
    <div class="bar-block">
      <?php $tp = min(100, (int)round($tempVal / 60 * 100)); ?>
      <div class="bar-label"><span>Temperature</span><span><?= $tempVal ?>°C</span></div>
      <div class="bar-track"><div class="bar-fill <?= $tempVal >= 45 ? 'fill-red' : ($tempVal >= 35 ? 'fill-yellow' : 'fill-green') ?>" style="width:<?= $tp ?>%"></div></div>
    </div>
    <?php endif; ?>

    <!-- Stats grid -->
    <div class="stats-grid">
      <div class="stat-cell">
        <div class="stat-lbl">Voltage</div>
        <div class="stat-val"><?= $volt !== null ? round((float)$volt,1) : '—' ?><span class="stat-unit">V</span></div>
      </div>
      <div class="stat-cell">
        <div class="stat-lbl">Current</div>
        <div class="stat-val"><?= $curr !== null ? round((float)$curr,1) : '—' ?><span class="stat-unit">A</span></div>
      </div>
      <div class="stat-cell">
        <div class="stat-lbl">Temp</div>
        <div class="stat-val <?= $tempClass ?>"><?= $tempVal !== null ? $tempVal : '—' ?><span class="stat-unit">°C</span></div>
      </div>
      <div class="stat-cell">
        <div class="stat-lbl">Capacity</div>
        <div class="stat-val"><?= $cap !== null ? round((float)$cap) : '—' ?><span class="stat-unit">Ah</span></div>
      </div>
      <?php if ($maxv !== null): ?>
      <div class="stat-cell">
        <div class="stat-lbl">Cell Max V</div>
        <div class="stat-val"><?= round((float)$maxv,3) ?><span class="stat-unit">V</span></div>
      </div>
      <?php endif; ?>
      <?php if ($minv !== null): ?>
      <div class="stat-cell">
        <div class="stat-lbl">Cell Min V</div>
        <div class="stat-val"><?= round((float)$minv,3) ?><span class="stat-unit">V</span></div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Cycles highlight -->
    <?php if ($cycles !== null): ?>
    <div class="cycles-highlight">
      <div>
        <div class="cycles-lbl">Charge Cycles</div>
        <div style="font-size:.6rem;color:var(--muted);margin-top:.1rem">Full charge/discharge cycles</div>
      </div>
      <div class="cycles-num"><?= number_format((int)round((float)$cycles)) ?></div>
    </div>
    <?php else: ?>
    <div class="cycles-highlight" style="opacity:.4">
      <div class="cycles-lbl">Charge Cycles</div>
      <div class="cycles-num">—</div>
    </div>
    <?php endif; ?>

    <!-- Health summary text -->
    <?php if ($sohVal !== null): ?>
    <div style="margin-top:.6rem;font-size:.72rem;color:var(--muted);line-height:1.5">
      <?php if ($sohVal >= 90): ?>
        <span style="color:var(--green)">✓ Excellent health</span> — battery is performing near new capacity.
      <?php elseif ($sohVal >= 75): ?>
        <span style="color:#7dd3fc">✓ Good health</span> — some capacity degradation, normal for age/cycles.
      <?php elseif ($sohVal >= 60): ?>
        <span style="color:var(--yellow)">⚠ Fair health</span> — noticeable capacity reduction. Monitor closely.
      <?php else: ?>
        <span style="color:var(--red)">✗ Poor health</span> — significant capacity loss. Consider replacement.
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div>
<?php endif; endforeach; ?>

</div><!-- /.bat-grid -->

<!-- ── Combined Battery Points ───────────────────────────────────────────────── -->
<?php if (!empty($sections['bat_combined']) || !empty($sections['bat1']) || !empty($sections['bat2'])): ?>
<div class="collapse-card" id="cc-battery">
  <div class="collapse-header" onclick="toggleCollapse('cc-battery')">
    <div class="collapse-title">🔋 All Battery Measure Points
      <span class="badge badge-ok" style="font-size:.6rem"><?= count($sections['bat_combined']) + count($sections['bat1']) + count($sections['bat2']) ?> points</span>
    </div>
    <span class="collapse-arrow open" id="arr-cc-battery">▶</span>
  </div>
  <div class="collapse-body" id="body-cc-battery">
    <?php
    $subSections = [
        'Battery 1 Points'  => $sections['bat1'],
        'Battery 2 Points'  => $sections['bat2'],
        'Combined / BMS'    => $sections['bat_combined'],
    ];
    foreach ($subSections as $subTitle => $points):
        if (empty($points)) continue;
    ?>
    <div class="sub-section">
      <div class="sub-title"><?= e($subTitle) ?> (<?= count($points) ?>)</div>
      <div class="data-table-wrap">
        <table>
          <thead><tr><th>Key</th><th>Name</th><th>Value</th><th>Unit</th></tr></thead>
          <tbody>
            <?php foreach ($points as $p): ?>
            <tr>
              <td class="key-col"><?= e($p['key'] ?? '') ?></td>
              <td><?= e($p['name'] ?? '') ?></td>
              <td class="val-col"><?= e((string)($p['value'] ?? '—')) ?></td>
              <td class="unit-col"><?= e($p['unit'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── Other Data Sections ───────────────────────────────────────────────────── -->
<?php
$otherSections = [
    ['id' => 'cc-pv',       'icon' => '☀️', 'label' => 'PV / Solar Strings',   'data' => $sections['pv']],
    ['id' => 'cc-grid',     'icon' => '🔌', 'label' => 'Grid',                 'data' => $sections['grid']],
    ['id' => 'cc-load',     'icon' => '🏠', 'label' => 'Load / Consumption',   'data' => $sections['load']],
    ['id' => 'cc-inv',      'icon' => '⚙️', 'label' => 'Inverter / Status',    'data' => $sections['inverter']],
    ['id' => 'cc-other',    'icon' => '📋', 'label' => 'Other Points',         'data' => $sections['other']],
];
foreach ($otherSections as $sec):
    if (empty($sec['data'])) continue;
?>
<div class="collapse-card" id="<?= $sec['id'] ?>">
  <div class="collapse-header" onclick="toggleCollapse('<?= $sec['id'] ?>')">
    <div class="collapse-title"><?= $sec['icon'] ?> <?= e($sec['label']) ?>
      <span class="badge" style="font-size:.6rem;background:rgba(0,212,255,.08);color:var(--muted);border:1px solid rgba(0,212,255,.2)"><?= count($sec['data']) ?></span>
    </div>
    <span class="collapse-arrow" id="arr-<?= $sec['id'] ?>">▶</span>
  </div>
  <div class="collapse-body hidden" id="body-<?= $sec['id'] ?>">
    <div class="data-table-wrap">
      <table>
        <thead><tr><th>Key</th><th>Name</th><th>Value</th><th>Unit</th></tr></thead>
        <tbody>
          <?php foreach ($sec['data'] as $p): ?>
          <tr>
            <td class="key-col"><?= e($p['key'] ?? '') ?></td>
            <td><?= e($p['name'] ?? '') ?></td>
            <td class="val-col"><?= e((string)($p['value'] ?? '—')) ?></td>
            <td class="unit-col"><?= e($p['unit'] ?? '') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endforeach; ?>

<!-- ── Raw API debug (hidden by default) ─────────────────────────────────────── -->
<?php if (!empty($allPoints)): ?>
<div class="collapse-card" id="cc-raw">
  <div class="collapse-header" onclick="toggleCollapse('cc-raw')">
    <div class="collapse-title">🔍 All <?= count($allPoints) ?> Device Data Points (raw dump)</div>
    <span class="collapse-arrow" id="arr-cc-raw">▶</span>
  </div>
  <div class="collapse-body hidden" id="body-cc-raw">
    <div class="data-table-wrap">
      <table>
        <thead><tr><th>#</th><th>Key</th><th>Name</th><th>Value</th><th>Unit</th></tr></thead>
        <tbody>
          <?php foreach ($allPoints as $i => $p): ?>
          <tr>
            <td style="color:var(--muted)"><?= $i+1 ?></td>
            <td class="key-col"><?= e($p['key'] ?? '') ?></td>
            <td><?= e($p['name'] ?? '') ?></td>
            <td class="val-col"><?= e((string)($p['value'] ?? '—')) ?></td>
            <td class="unit-col"><?= e($p['unit'] ?? '') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

</div><!-- /.main -->

<footer>
  Deye Cloud OpenAPI · eu1-developer.deyecloud.com · Station <?= DEYE_STATION ?> · Device <?= e($deviceSn ?: DEYE_SN_HINT) ?><br>
  <span id="auto-refresh-msg">Auto-refreshes every 30 s</span>
</footer>

<script>
// ── Collapsible sections ──────────────────────────────────────────────────────
function toggleCollapse(id) {
  const body  = document.getElementById('body-' + id);
  const arrow = document.getElementById('arr-' + id);
  if (!body) return;
  const hidden = body.classList.toggle('hidden');
  if (arrow) arrow.classList.toggle('open', !hidden);
}

// ── Auto-refresh countdown ────────────────────────────────────────────────────
let countdown = 30;
const msg = document.getElementById('auto-refresh-msg');
const timer = setInterval(() => {
  countdown--;
  if (msg) msg.textContent = `Auto-refreshes in ${countdown}s`;
  if (countdown <= 0) {
    clearInterval(timer);
    location.reload();
  }
}, 1000);
</script>

</body>
</html>
