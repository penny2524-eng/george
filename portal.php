<?php
// ── Credentials ──────────────────────────────────────────────────────────────
define('CAM_USER', 'admin');
define('CAM_PASS', 'ismart123456');

define('MT_HOST',  'http://192.168.50.251');
define('MT_USER',  'george');
define('MT_PASS',  'Penny2000!');

define('SY_HOST',  'http://192.168.50.99:5000');
define('SY_USER',  'george');
define('SY_PASS',  'Penny2000!');

define('PH1_HOST', 'http://192.168.50.247');
define('PH2_HOST', 'http://192.168.50.248');

define('RT_HOST',  'https://192.168.50.1');
define('RT_PASS',  'Penny2000!');
define('RT_SN',    'H1U90XY002203');

define('TS_KEY',       'tskey-api-kyH8Krnxyc11CNTRL-q6PR5RuhjafYQJoqU1ExWfeRD2FUMtPXf');

define('DEYE_APPID',   '202604284470012');
define('DEYE_SECRET',  'bd6abff1bb8c315065df6fd5a63286d1');
define('DEYE_EMAIL',   'penny2524@gmail.com');
define('DEYE_PASS',    'Penny2000!');
define('DEYE_STATION', '61523693');

// ── Camera proxies ────────────────────────────────────────────────────────────
if (isset($_GET['snapshot'])) {
    $octet = (int)$_GET['snapshot'];
    if ($octet < 151 || $octet > 158) { http_response_code(403); exit; }

    // Extract a single JPEG frame from the MJPEG stream by watching for
    // JPEG SOI (FFD8) and EOI (FFD9) markers — avoids the broken snapshot.cgi
    $buf = '';
    $ch  = curl_init("http://192.168.50.{$octet}/cgi-bin/mjpg/video.cgi?channel=1&subtype=1");
    curl_setopt_array($ch, [
        CURLOPT_HTTPAUTH       => CURLAUTH_DIGEST,
        CURLOPT_USERPWD        => CAM_USER . ':' . CAM_PASS,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_WRITEFUNCTION  => function($ch, $data) use (&$buf) {
            $buf .= $data;
            $s = strpos($buf, "\xff\xd8");
            $e = ($s !== false) ? strpos($buf, "\xff\xd9", $s) : false;
            if ($s !== false && $e !== false) return -1; // got a full frame, stop
            return strlen($data);
        },
    ]);
    curl_exec($ch);
    curl_close($ch);

    $s = strpos($buf, "\xff\xd8");
    $e = ($s !== false) ? strpos($buf, "\xff\xd9", $s) : false;
    if ($s !== false && $e !== false) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo substr($buf, $s, $e - $s + 2);
    } else {
        http_response_code(503);
    }
    exit;
}


// ── Auto-login handlers ───────────────────────────────────────────────────────
if (isset($_GET['open'])) {
    if ($_GET['open'] === 'synology') {
        // Login via Synology API and collect Set-Cookie headers
        $cookies = [];
        $ch = curl_init(SY_HOST . '/webapi/entry.cgi');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'api'     => 'SYNO.API.Auth',
                'version' => '7',
                'method'  => 'login',
                'account' => SY_USER,
                'passwd'  => SY_PASS,
                'session' => 'DSMDesktop',
                'format'  => 'cookie',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HEADERFUNCTION => function($ch, $header) use (&$cookies) {
                if (stripos($header, 'Set-Cookie:') === 0) {
                    // Parse: name=value; options...
                    $parts = explode(';', substr($header, 11));
                    [$name, $val] = array_map('trim', explode('=', trim($parts[0]), 2));
                    $exp = 0;
                    foreach ($parts as $p) {
                        if (stripos(trim($p), 'max-age=') === 0) {
                            $exp = time() + (int)explode('=', $p, 2)[1];
                        }
                    }
                    $cookies[$name] = ['value' => $val, 'expires' => $exp];
                }
                return strlen($header);
            },
        ]);
        curl_exec($ch);
        curl_close($ch);

        // Forward cookies to browser — cookies are port-agnostic so they reach :5000
        foreach ($cookies as $name => $c) {
            setcookie($name, $c['value'], [
                'expires'  => $c['expires'],
                'path'     => '/',
                'domain'   => '192.168.50.99',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        header('Location: http://192.168.50.99:5000/');
        exit;
    }
    if ($_GET['open'] === 'mikrotik') {
        header('Content-Type: text/html; charset=utf-8');
        $login = json_encode('login=' . MT_USER . '|' . MT_PASS);
        echo <<<HTML
        <!doctype html><html><head><meta charset="utf-8"><title>MikroTik</title>
        <style>body{background:#020810;color:#cce5f0;font-family:'Courier New',monospace;
               display:flex;align-items:center;justify-content:center;height:100vh;margin:0;flex-direction:column;gap:.75rem;}
          h2{color:#00d4ff;font-size:1rem;font-weight:700;letter-spacing:.05em;}
          p{color:#4a6878;font-size:.8rem;}
        </style>
        </head><body>
        <h2>⟶ MIKROTIK</h2>
        <p>Connecting…</p>
        <script>
        window.name = $login;
        location.replace('http://192.168.50.251/webfig/');
        </script>
        </body></html>
        HTML;
        exit;
    }
    if ($_GET['open'] === 'router') {
        // The Ruijie login page has a built-in URL auto-login:
        // ?pass=<password>&type=plaintext triggers auto-fill + GibberishAES encrypt + submit
        header('Location: ' . RT_HOST . '/cgi-bin/luci/?pass=' . urlencode(RT_PASS) . '&type=plaintext');
        exit;
    }

    exit; // unknown open param
}

// ── Speedtest trigger (long-running, called async from JS) ───────────────────
if (isset($_GET['action']) && $_GET['action'] === 'run_speedtest') {
    header('Content-Type: application/json');
    echo json_encode(run_speedtest());
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'speedtest_debug') {
    header('Content-Type: application/json');
    echo json_encode([
        'shell_exec_enabled' => function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', ini_get('disable_functions')))),
        'which_speedtest'    => @shell_exec('which speedtest 2>&1'),
        'which_speedtest_cli'=> @shell_exec('which speedtest-cli 2>&1'),
        'path'               => @shell_exec('echo $PATH'),
        'whoami'             => @shell_exec('whoami'),
        'ls_usr_local_bin'   => @shell_exec('ls /usr/local/bin/ 2>&1 | grep -i speed'),
        'ls_usr_bin'         => @shell_exec('ls /usr/bin/ 2>&1 | grep -i speed'),
        'find_speedtest'     => @shell_exec('find /usr /opt /volume1 -name "speedtest*" -type f 2>/dev/null'),
    ]);
    exit;
}

// ── JSON API handler ──────────────────────────────────────────────────────────

if (isset($_GET['check'])) {
    header('Content-Type: application/json');

    if ($_GET['check'] === 'debug') {
        $targets = [
            'mikrotik'      => 'http://192.168.50.251/rest/system/resource',
            'pihole1_v6'    => 'http://192.168.50.247/api/stats/summary',
            'pihole1_v5'    => 'http://192.168.50.247/admin/api.php?summaryRaw',
            'pihole2_v6'    => 'http://192.168.50.248/api/stats/summary',
            'pihole2_v5'    => 'http://192.168.50.248/admin/api.php?summaryRaw',
            'synology'      => 'http://192.168.50.99:5000/webapi/entry.cgi',
            'router'        => 'https://192.168.50.1/cgi-bin/luci/api/auth',
            'printer'       => 'http://192.168.50.57/',
            'tesla'         => 'http://192.168.50.49/api/1/vitals',
            'sonos'         => 'http://192.168.50.41:1400/xml/device_description.xml',
            'google_home'   => 'http://192.168.50.29:8008/setup/eureka_info',
            'camera_151'    => 'http://192.168.50.151/',
            'linksys_72'    => 'http://192.168.50.72/',
        ];
        $out = ['server_ip' => gethostbyname(gethostname()), 'checks' => []];
        foreach ($targets as $name => $url) {
            [, $code, $err] = curl_get($url);
            $out['checks'][$name] = ['code' => $code, 'err' => $err ?: null];
        }
        echo json_encode($out, JSON_PRETTY_PRINT);
        exit;
    }

    $out = match($_GET['check']) {
        'mikrotik'  => check_mikrotik(),
        'pihole'    => check_pihole(PH1_HOST),
        'pihole2'   => check_pihole(PH2_HOST),
        'synology'  => check_synology(),
        'router'    => check_ruijie(),
        'cameras'       => check_cameras(),
        'linksys'       => check_linksys(),
        'printer'       => check_http('http://192.168.50.57/', 'Epson Printer'),
        'tesla_charger' => check_tesla_charger(),
        'sonos'         => check_sonos(),
        'google_home'   => check_google_home(),
        'deye'          => check_deye(),
        'tailscale'     => check_tailscale(),
        'speedtest'     => check_speedtest(),
        default         => ['online' => false, 'error' => 'unknown check'],
    };
    echo json_encode($out);
    exit;
}

// ── Check functions ───────────────────────────────────────────────────────────
function tcp_check($ip, $port, $timeout = 2) {
    $fp = @fsockopen($ip, $port, $errno, $errstr, $timeout);
    if ($fp) { fclose($fp); return true; }
    return false;
}

function curl_get($url, $opts = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, $opts + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [$body, $code, $err];
}

function check_mikrotik() {
    $common = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_USERPWD        => MT_USER . ':' . MT_PASS,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
    ];

    $ch1 = curl_init(MT_HOST . '/rest/system/resource');
    curl_setopt_array($ch1, $common);
    $ch2 = curl_init(MT_HOST . '/rest/ip/dhcp-server/lease?status=bound');
    curl_setopt_array($ch2, $common);

    $mh = curl_multi_init();
    curl_multi_add_handle($mh, $ch1);
    curl_multi_add_handle($mh, $ch2);
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh);
    } while ($running > 0 && $status === CURLM_OK);

    $body1 = curl_multi_getcontent($ch1);
    $code1 = curl_getinfo($ch1, CURLINFO_HTTP_CODE);
    $err1  = curl_error($ch1);
    $body2 = curl_multi_getcontent($ch2);

    curl_multi_remove_handle($mh, $ch1);
    curl_multi_remove_handle($mh, $ch2);
    curl_multi_close($mh);
    curl_close($ch1);
    curl_close($ch2);

    if ($code1 !== 200) return ['online' => false, 'error' => "HTTP $code1" . ($err1 ? " / $err1" : '')];
    $d = json_decode($body1, true);
    if (!$d) return ['online' => false, 'error' => 'bad json'];

    $leases = count(json_decode($body2 ?: '[]', true) ?? []);

    return [
        'online'   => true,
        'version'  => $d['version'] ?? '—',
        'uptime'   => $d['uptime'] ?? '—',
        'cpu'      => (int)($d['cpu-load'] ?? 0),
        'mem_free' => (int)($d['free-memory'] ?? 0),
        'mem_tot'  => (int)($d['total-memory'] ?? 1),
        'board'    => $d['board-name'] ?? '—',
        'leases'   => $leases,
    ];
}

function check_pihole($host) {
    // Try Pi-hole v6 FTL REST API first
    [$sb, $sc, $se] = curl_get($host . '/api/stats/summary');
    $s = json_decode($sb, true);

    if ($s && isset($s['queries'])) {
        // v6 path
        [$bb] = curl_get($host . '/api/dns/blocking');
        $b = json_decode($bb, true);
        return [
            'online'   => true,
            'blocking' => ($b['blocking'] ?? '') === 'enabled',
            'domains'  => $s['gravity']['domains_being_blocked'] ?? 0,
            'total'    => $s['queries']['total'] ?? 0,
            'blocked'  => $s['queries']['blocked'] ?? 0,
            'pct'      => round($s['queries']['percent_blocked'] ?? 0, 1),
            'clients'  => $s['clients']['active'] ?? 0,
            'gravity_updated' => isset($s['gravity']['last_update'])
                ? date('d M H:i', $s['gravity']['last_update']) : '—',
        ];
    }

    // Fall back to Pi-hole v5 API
    [$sb5, $sc5, $se5] = curl_get($host . '/admin/api.php?summaryRaw');
    $s5 = json_decode($sb5, true);
    if (!$s5) return ['online' => false, 'error' => "v6:HTTP{$sc}/{$se} v5:HTTP{$sc5}/{$se5}"];

    [$bb5] = curl_get($host . '/admin/api.php?status');
    $b5 = json_decode($bb5, true);
    return [
        'online'   => true,
        'blocking' => ($b5['status'] ?? '') === 'enabled',
        'domains'  => (int)($s5['domains_being_blocked'] ?? 0),
        'total'    => (int)($s5['dns_queries_today'] ?? 0),
        'blocked'  => (int)($s5['ads_blocked_today'] ?? 0),
        'pct'      => round((float)($s5['ads_percentage_today'] ?? 0), 1),
        'clients'  => (int)($s5['unique_clients'] ?? 0),
        'gravity_updated' => '—',
    ];
}

function check_synology() {
    // Login
    [$lb, $lc] = curl_get(SY_HOST . '/webapi/entry.cgi', [
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'api'     => 'SYNO.API.Auth',
            'version' => '7',
            'method'  => 'login',
            'account' => SY_USER,
            'passwd'  => SY_PASS,
            'session' => 'DSMDesktop',
            'format'  => 'sid',
        ]),
    ]);
    if ($lc !== 200) return ['online' => false];
    $login = json_decode($lb, true);
    if (!($login['success'] ?? false)) return ['online' => false, 'error' => 'auth failed'];
    $sid = $login['data']['sid'];

    // System info
    [$ib] = curl_get(SY_HOST . "/webapi/entry.cgi?api=SYNO.DSM.Info&version=2&method=getinfo&_sid=$sid");
    $info = json_decode($ib, true);

    // Storage
    [$stb] = curl_get(SY_HOST . "/webapi/entry.cgi?api=SYNO.Core.System.Utilization&version=1&method=get&_sid=$sid");
    $util = json_decode($stb, true);

    // Volume usage via Storage API
    [$vb] = curl_get(SY_HOST . "/webapi/entry.cgi?api=SYNO.Storage.CGI.Storage&version=1&method=load_info&_sid=$sid");
    $vol = json_decode($vb, true);

    $result = [
        'online'    => true,
        'model'     => $info['data']['model'] ?? 'Synology NAS',
        'dsm_ver'   => $info['data']['version_string'] ?? '—',
        'uptime'    => null,
        'cpu'       => null,
        'vol_use'   => null,
        'vol_tot'   => null,
        'sys_temp'  => isset($info['data']['temperature']) ? (int)$info['data']['temperature'] : null,
        'temp_warn' => (bool)($info['data']['temperature_warn'] ?? false),
    ];

    if (!empty($util['data'])) {
        $u = $util['data'];
        $result['cpu'] = $u['cpu']['user_load'] ?? null;
    }

    if (!empty($vol['data']['volumes'])) {
        $v = $vol['data']['volumes'][0];
        $result['vol_use'] = $v['used_size'] ?? null;
        $result['vol_tot'] = $v['total_size'] ?? null;
    }

    // Logout silently
    curl_get(SY_HOST . "/webapi/entry.cgi?api=SYNO.API.Auth&version=1&method=logout&session=DSMDesktop&_sid=$sid");

    return $result;
}

function check_ruijie() {
    // Login
    $ch = curl_init(RT_HOST . '/cgi-bin/luci/api/auth');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['method' => 'login', 'params' => [
            'username' => 'admin',
            'time'     => time(),
            'password' => RT_PASS,
        ]]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json;charset=UTF-8'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) return ['online' => false];
    $login = json_decode($body, true);
    $sid   = $login['data']['sid'] ?? null;
    if (!$sid) return ['online' => false];

    // Uptime
    $ch2 = curl_init(RT_HOST . '/cgi-bin/luci/api/overview?auth=' . urlencode($sid));
    curl_setopt_array($ch2, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['method' => 'getUptime', 'params' => null]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json;charset=UTF-8',
            'Cookie: ' . RT_SN . '=' . $sid,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $ubody = curl_exec($ch2);
    curl_close($ch2);

    $secs   = json_decode($ubody, true)['data'] ?? null;
    $uptime = null;
    if (is_numeric($secs)) {
        $d = floor($secs / 86400);
        $h = floor(($secs % 86400) / 3600);
        $m = floor(($secs % 3600) / 60);
        $uptime = ($d > 0 ? "{$d}d " : '') . "{$h}h {$m}m";
    }

    return [
        'online' => true,
        'model'  => 'RG-EG710-XS',
        'uptime' => $uptime ?? '—',
    ];
}

function check_tesla_charger() {
    [$vb] = curl_get('http://192.168.50.49/api/1/vitals');
    [$lb] = curl_get('http://192.168.50.49/api/1/lifetime');
    $v = json_decode($vb, true);
    $l = json_decode($lb, true);
    if (!$v) return ['online' => false];

    $s = (int)($v['uptime_s'] ?? 0);
    $uptime = ($s >= 86400 ? floor($s/86400).'d ' : '') . floor(($s%86400)/3600).'h';

    $state = match((int)($v['evse_state'] ?? 0)) {
        2, 3    => 'Charging',
        6       => 'Connected',
        default => 'Standby',
    };

    return [
        'online'           => true,
        'state'            => $state,
        'vehicle_connected'=> (bool)($v['vehicle_connected'] ?? false),
        'grid_v'           => round($v['grid_v'] ?? 0, 1),
        'session_kwh'      => round(($v['session_energy_wh'] ?? 0) / 1000, 2),
        'total_kwh'        => round(($l['energy_wh'] ?? 0) / 1000, 1),
        'charge_starts'    => (int)($l['charge_starts'] ?? 0),
        'temp_c'           => round($v['pcba_temp_c'] ?? 0, 1),
        'uptime'           => $uptime,
        'firmware'         => '', // filled by version endpoint if needed
    ];
}

function check_sonos() {
    [$db, $dc] = curl_get('http://192.168.50.41:1400/xml/device_description.xml');
    if ($dc === 0) return ['online' => false];

    $soap = function($action, $service, $body) {
        $ch = curl_init("http://192.168.50.41:1400/MediaRenderer/$service/Control");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '<?xml version="1.0" encoding="utf-8"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/"><s:Body>'.$body.'</s:Body></s:Envelope>',
            CURLOPT_HTTPHEADER     => ['Content-Type: text/xml; charset="utf-8"', "SOAPACTION: \"urn:schemas-upnp-org:service:$action\""],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
        ]);
        $r = curl_exec($ch); curl_close($ch);
        return $r ?: '';
    };

    $tb = $soap('AVTransport:1#GetTransportInfo', 'AVTransport',
        '<u:GetTransportInfo xmlns:u="urn:schemas-upnp-org:service:AVTransport:1"><InstanceID>0</InstanceID></u:GetTransportInfo>');
    $vb = $soap('RenderingControl:1#GetVolume', 'RenderingControl',
        '<u:GetVolume xmlns:u="urn:schemas-upnp-org:service:RenderingControl:1"><InstanceID>0</InstanceID><Channel>Master</Channel></u:GetVolume>');

    preg_match('/<CurrentTransportState>([^<]+)</', $tb, $tm);
    preg_match('/<CurrentVolume>(\d+)</', $vb, $vm);

    preg_match('/<friendlyName>([^<]+)</', $db, $nm);
    $name = preg_replace('/^[\d\.:]+\s*-\s*/', '', $nm[1] ?? 'Sonos');

    return [
        'online'  => true,
        'name'    => $name,
        'state'   => $tm[1] ?? 'UNKNOWN',
        'volume'  => (int)($vm[1] ?? 0),
    ];
}

function check_google_home() {
    [$body, $code, $err] = curl_get('http://192.168.50.29:8008/setup/eureka_info?params=device_info,name,uptime,locale');

    // Newer firmware blocks the local API (returns 403 or empty) — but the device is reachable
    if ($code === 0) return ['online' => false, 'error' => $err];
    if ($code === 403 || $code >= 400) {
        return ['online' => true, 'name' => 'Google Home Mini', 'build' => '—', 'uptime' => '—', 'signal' => 0, 'ssid' => '—', 'note' => 'local API restricted'];
    }

    $d = json_decode($body, true);
    if (!$d) return ['online' => false, 'error' => "HTTP $code empty body"];

    $s  = (int)($d['uptime'] ?? 0);
    $up = ($s >= 86400 ? floor($s/86400).'d ' : '') . floor(($s%86400)/3600).'h '.floor(($s%3600)/60).'m';

    return [
        'online'  => true,
        'name'    => $d['name'] ?? 'Google Home',
        'build'   => $d['cast_build_revision'] ?? '—',
        'uptime'  => $up,
        'signal'  => (int)($d['signal_level'] ?? 0),
        'ssid'    => $d['ssid'] ?? '—',
    ];
}

function check_deye() {
    $base = 'https://eu1-developer.deyecloud.com/v1.0';

    // Authenticate
    $ch = curl_init($base . '/account/token?appId=' . DEYE_APPID);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'appSecret' => DEYE_SECRET,
            'email'     => DEYE_EMAIL,
            'password'  => hash('sha256', DEYE_PASS),
            'companyId' => '0',
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $ab = curl_exec($ch);
    $ac = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($ac !== 200) return ['online' => false, 'error' => "auth $ac"];
    $auth  = json_decode($ab, true);
    $token = $auth['accessToken'] ?? $auth['data']['accessToken'] ?? null;
    if (!$token) return ['online' => false, 'error' => 'no token'];

    $hdrs = ['Content-Type: application/json', 'Authorization: Bearer ' . $token];

    $postJson = function($url, $body) use ($hdrs) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => $hdrs,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $r = curl_exec($ch); curl_close($ch);
        return json_decode($r, true);
    };

    // Parallel: station summary + device list
    $mh = curl_multi_init();
    $chs = [];
    foreach ([
        'latest' => [$base.'/station/latest', ['stationId' => DEYE_STATION]],
        'devices'=> [$base.'/station/device',  ['stationIds' => [(int)DEYE_STATION]]],
    ] as $key => [$url, $body]) {
        $c = curl_init($url);
        curl_setopt_array($c, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => $hdrs,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        curl_multi_add_handle($mh, $c);
        $chs[$key] = $c;
    }
    do { $status = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh); }
    while ($running > 0 && $status === CURLM_OK);

    $st  = json_decode(curl_multi_getcontent($chs['latest']),  true);
    $dev = json_decode(curl_multi_getcontent($chs['devices']), true);
    foreach ($chs as $c) { curl_multi_remove_handle($mh, $c); curl_close($c); }
    curl_multi_close($mh);

    if (empty($st['success'])) return ['online' => false, 'error' => ($st['msg'] ?? 'no data')];

    // Find inverter SN and collect all device SNs from device list
    $invSn  = null;
    $allSns = [];
    foreach ($dev['deviceListItems'] ?? [] as $d) {
        $sn   = $d['deviceSn'] ?? null;
        $type = strtolower($d['deviceType'] ?? '');
        if (!$sn) continue;
        $allSns[] = $sn;
        if (str_contains($type, 'inverter')) $invSn = $sn;
    }
    // Always include the two physical battery module SNs visible in Deye Cloud
    foreach (['16903000D6120043', '25407000E5140658'] as $batSn) {
        if (!in_array($batSn, $allSns, true)) $allSns[] = $batSn;
    }

    // Helper: extract battery fields from a device's own dataList
    // Key names confirmed from live API probe on 2025-07-25
    $extractBat = function(array $dataList, string $sn): array {
        $kv2 = [];
        foreach ($dataList as $item) { $kv2[$item['key']] = $item['value']; }
        $b2 = function(array $keys) use ($kv2): ?float {
            foreach ($keys as $k) {
                if (isset($kv2[$k]) && $kv2[$k] !== '') return (float)$kv2[$k];
            }
            return null;
        };
        return [
            'sn'       => $sn,
            'soc'      => $b2(['SOC','BatterySOC','BatSOC','Battery1SOC','Bat1SOC']),
            'volt'     => $b2(['BatteryVoltage','Voltage','Battery1Voltage','Bat1Volt','BattVolt']),
            'curr'     => $b2(['BatteryCurrent','Current','Battery1Current','Bat1Current','BattCurr']),
            'power'    => $b2(['BatteryPower','Power','Battery1Power','Bat1Power','BattPower']),
            'temp'     => $b2(['Temperature- Battery','BatteryTemperature','Battery1Temp',
                               'Bat1Temp','BatteryTemp','Temperature','BatTemp']),
            'rated_ah' => $b2(['BatteryRatedCapacity','RatedCapacity','BatteryCapacity','BatCapacity']),
            // Cycle count and SoH are not exposed by the Deye Cloud API for this inverter model
        ];
    };

    // Fetch per-device real-time data for ALL devices at once
    $extra = [
        'temp_bat' => null, 'temp_ac' => null, 'temp_dc' => null,
        'pv' => [], 'bat1' => [], 'bat2' => [],
        'total_charge_kwh' => null, 'total_discharge_kwh' => null,
        'daily_charge_kwh' => null, 'daily_discharge_kwh' => null,
    ];
    if ($allSns) {
        $devData    = $postJson($base.'/device/latest', ['deviceList' => $allSns]);
        $batEntries = []; // non-inverter device entries

        foreach ($devData['deviceDataList'] ?? [] as $devEntry) {
            $sn       = $devEntry['deviceSn'] ?? '';
            $dataList = $devEntry['dataList']  ?? [];

            if ($sn === $invSn) {
                // Inverter — extract temps, PV strings, and all battery metrics
                $kv = [];
                foreach ($dataList as $item) { $kv[$item['key']] = $item['value']; }
                $bv = function(array $keys) use ($kv): ?float {
                    foreach ($keys as $k) {
                        if (isset($kv[$k]) && $kv[$k] !== '') return (float)$kv[$k];
                    }
                    return null;
                };
                $extra['temp_bat'] = $bv(['Temperature- Battery','BatteryTemp','BatTemp']);
                $extra['temp_dc']  = $bv(['DC Temperature','DcTemp']);
                $extra['temp_ac']  = $bv(['AC Temperature','AcTemp']);
                foreach ([1, 2, 3] as $n) {
                    $v = (float)($kv["DCVoltagePV$n"] ?? 0);
                    $a = (float)($kv["DCCurrentPV$n"] ?? 0);
                    $w = (float)($kv["DCPowerPV$n"]   ?? 0);
                    if ($v > 0) $extra['pv'][] = ['n' => $n, 'v' => $v, 'a' => $a, 'w' => (int)$w];
                }
                // Energy throughput metrics (confirmed available from live API probe)
                $fv = fn($k) => isset($kv[$k]) && $kv[$k] !== '' ? (float)$kv[$k] : null;
                $extra['total_charge_kwh']    = $fv('TotalChargeEnergy');
                $extra['total_discharge_kwh'] = $fv('TotalDischargeEnergy');
                $extra['daily_charge_kwh']    = $fv('DailyChargingEnergy');
                $extra['daily_discharge_kwh'] = $fv('DailyDischargingEnergy');
                // Battery metrics reported by inverter (combined for both physical packs)
                $extra['_invBat'] = $extractBat($dataList, '16903000D6120043');
            } else {
                $batEntries[] = ['sn' => $sn, 'dl' => $dataList];
            }
        }

        // Sort battery devices by SN for deterministic ordering
        usort($batEntries, fn($a, $b) => strcmp($a['sn'], $b['sn']));

        if (isset($batEntries[0])) {
            $extra['bat1'] = $extractBat($batEntries[0]['dl'], $batEntries[0]['sn']);
        }
        if (isset($batEntries[1])) {
            $extra['bat2'] = $extractBat($batEntries[1]['dl'], $batEntries[1]['sn']);
        }

        // Fallback: battery modules don't return data via /device/latest — use inverter-level fields
        $hasData = fn($b) => is_array($b) && array_filter($b, fn($v) => $v !== null && $v !== '') !== [];
        if (!$hasData($extra['bat1']) && $hasData($extra['_invBat'] ?? [])) {
            $extra['bat1'] = $extra['_invBat'];
        }
        // bat2: second physical pack — data is combined in inverter, show SN as placeholder
        if (!$hasData($extra['bat2'])) {
            $extra['bat2'] = ['sn' => '25407000E5140658'];
        }
        unset($extra['_invBat']);
    }

    return array_merge([
        'online'       => true,
        'generation_w' => $st['generationPower']     ?? null,
        'battery_soc'  => $st['batterySOC']           ?? null,
        'battery_w'    => $st['batteryPower']          ?? null,
        'consumption_w'=> $st['consumptionPower']      ?? null,
        'grid_w'       => $st['wirePower']             ?? null,
        'day_kwh'      => $st['generationValue']       ?? null,
        'total_kwh'    => $st['totalGenerationValue']  ?? null,
        'last_update'  => $st['lastUpdateTime']        ?? null,
    ], $extra);
}


function check_http($url, $name) {
    $p    = parse_url($url);
    $ip   = $p['host'];
    $port = $p['port'] ?? ($p['scheme'] === 'https' ? 443 : 80);
    $up   = tcp_check($ip, $port);
    return ['online' => $up, 'name' => $name];
}

function check_cameras() {
    $results = [];
    for ($i = 151; $i <= 158; $i++) {
        $ip = "192.168.50.$i";
        $results[$ip] = tcp_check($ip, 80, 1);
    }
    $online = count(array_filter($results));
    return ['online' => $online > 0, 'up' => $online, 'total' => 8, 'cameras' => $results];
}

function check_linksys() {
    $nodes = ['192.168.50.72', '192.168.50.85', '192.168.50.86'];
    $up = 0;
    foreach ($nodes as $ip) {
        if (tcp_check($ip, 80, 1)) $up++;
    }
    return ['online' => $up > 0, 'up' => $up, 'total' => count($nodes)];
}

function check_tailscale() {
    [$body, $code] = curl_get('https://api.tailscale.com/api/v2/tailnet/-/devices?fields=all', [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . TS_KEY],
    ]);
    if ($code !== 200) return ['online' => false, 'error' => "HTTP $code"];
    $data = json_decode($body, true);
    if (!isset($data['devices'])) return ['online' => false, 'error' => 'no devices'];

    $tailnet = '';
    $devices = [];
    foreach ($data['devices'] as $d) {
        if ($d['isExternal']) continue;

        $ipv4 = '';
        foreach ($d['addresses'] as $addr) {
            if (str_starts_with($addr, '100.')) { $ipv4 = $addr; break; }
        }

        if (!$tailnet && preg_match('/\.(tailnet-[^.]+)\.ts\.net$/', $d['name'], $m)) {
            $tailnet = $m[1];
        }

        $hostname = ($d['hostname'] && $d['hostname'] !== 'localhost')
            ? $d['hostname']
            : preg_replace('/\.(tailnet-[^.]+\.ts\.net|ts\.net)$/', '', $d['name']);

        $lastSeenAgo = '—';
        if (!empty($d['lastSeen']) && $d['lastSeen'] !== '0001-01-01T00:00:00Z') {
            $diff = time() - strtotime($d['lastSeen']);
            $lastSeenAgo = match(true) {
                $diff < 120   => 'just now',
                $diff < 3600  => floor($diff / 60) . 'm ago',
                $diff < 86400 => floor($diff / 3600) . 'h ago',
                default       => floor($diff / 86400) . 'd ago',
            };
        }

        $version = preg_replace('/^(\d+\.\d+\.\d+).*$/', '$1', $d['clientVersion'] ?? '') ?: '—';

        // Extract private (RFC1918) endpoint IPs, deduplicated, 192.168 preferred first
        $privateIps = [];
        foreach ($d['endpoints'] ?? [] as $ep) {
            if (preg_match('/^(\d+\.\d+\.\d+\.\d+):\d+$/', $ep, $em)) {
                $ip = $em[1];
                if (preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2\d|3[01])\.)/', $ip) && !in_array($ip, $privateIps)) {
                    $privateIps[] = $ip;
                }
            }
        }
        usort($privateIps, fn($a, $b) => (str_starts_with($b, '192.168.') <=> str_starts_with($a, '192.168.'))
            ?: (str_starts_with($b, '10.') <=> str_starts_with($a, '10.')));

        $devices[] = [
            'hostname'    => $hostname,
            'ipv4'        => $ipv4,
            'privateIps'  => $privateIps,
            'os'          => $d['os'] ?? '—',
            'online'      => (bool)($d['connectedToControl'] ?? false),
            'lastSeen'    => $lastSeenAgo,
            'version'     => $version,
            'updateAvail' => (bool)($d['updateAvailable'] ?? false),
        ];
    }

    usort($devices, fn($a, $b) => $b['online'] <=> $a['online'] ?: strcmp($a['hostname'], $b['hostname']));

    $up = count(array_filter($devices, fn($d) => $d['online']));
    return [
        'online'  => $up > 0,
        'up'      => $up,
        'total'   => count($devices),
        'tailnet' => $tailnet,
        'devices' => $devices,
    ];
}

function speedtest_cache_path() {
    return sys_get_temp_dir() . '/jgspace_speedtest.json';
}

function check_speedtest() {
    $cache = speedtest_cache_path();
    if (file_exists($cache)) {
        $data = json_decode(file_get_contents($cache), true);
        if ($data && ($data['online'] ?? false)) {
            $data['age_s'] = time() - filemtime($cache);
            return $data;
        }
    }
    return ['online' => false, 'untested' => true];
}

function shell_run($cmd) {
    if (function_exists('shell_exec')) {
        $out = @shell_exec($cmd);
        if ($out !== null && $out !== '') return $out;
    }
    if (function_exists('exec')) {
        $lines = []; $rc = 0;
        @exec($cmd, $lines, $rc);
        if ($lines) return implode("\n", $lines);
    }
    return null;
}

function run_speedtest() {
    $cache = speedtest_cache_path();

    $disabled = array_map('trim', explode(',', ini_get('disable_functions')));
    if (in_array('shell_exec', $disabled) && in_array('exec', $disabled)) {
        return ['online' => false, 'error' => 'shell_exec and exec are disabled in PHP'];
    }

    if (!file_exists('/usr/local/bin/speedtest')) {
        return ['online' => false, 'error' => 'binary not found at /usr/local/bin/speedtest'];
    }

    if (!is_executable('/usr/local/bin/speedtest')) {
        return ['online' => false, 'error' => 'speedtest exists but is not executable by web user (' . get_current_user() . ')'];
    }

    // Official Ookla speedtest CLI
    $out = shell_run('/usr/local/bin/speedtest --accept-license --accept-gdpr -f json 2>&1');
    $d = json_decode($out, true);
    if ($d && isset($d['download']['bandwidth'])) {
        $result = [
            'online'     => true,
            'download'   => round($d['download']['bandwidth'] * 8 / 1e6, 2),
            'upload'     => round($d['upload']['bandwidth'] * 8 / 1e6, 2),
            'ping'       => round($d['ping']['latency'] ?? 0, 1),
            'jitter'     => round($d['ping']['jitter'] ?? 0, 1),
            'server'     => trim(($d['server']['name'] ?? '') . ', ' . ($d['server']['location'] ?? '')),
            'server_id'  => $d['server']['id'] ?? null,
            'isp'        => $d['isp'] ?? '—',
            'result_url' => $d['result']['url'] ?? null,
        ];
        file_put_contents($cache, json_encode($result));
        return $result;
    }

    // Fallback: speedtest-cli (Python package)
    $out2 = shell_run('/usr/local/bin/speedtest-cli --json 2>&1');
    $d2 = json_decode($out2, true);
    if ($d2 && isset($d2['download'])) {
        $result = [
            'online'     => true,
            'download'   => round($d2['download'] / 1e6, 2),
            'upload'     => round($d2['upload'] / 1e6, 2),
            'ping'       => round($d2['ping'] ?? 0, 1),
            'jitter'     => null,
            'server'     => trim(($d2['server']['name'] ?? '') . ', ' . ($d2['server']['sponsor'] ?? '')),
            'server_id'  => $d2['server']['id'] ?? null,
            'isp'        => $d2['client']['isp'] ?? '—',
            'result_url' => $d2['share'] ?? null,
        ];
        file_put_contents($cache, json_encode($result));
        return $result;
    }

    return ['online' => false, 'error' => trim($out ?? $out2 ?? 'no output from speedtest')];
}

function fmt_bytes($b) {
    if ($b === null) return '—';
    $b = (float)$b;
    if ($b >= 1e12) return round($b / 1e12, 1) . ' TB';
    if ($b >= 1e9)  return round($b / 1e9, 1) . ' GB';
    if ($b >= 1e6)  return round($b / 1e6, 1) . ' MB';
    return round($b / 1e3, 1) . ' KB';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>JG-Space — Network Portal</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:       #182d4e;
  --surface:  rgba(35, 82, 150, 0.70);
  --border:   rgba(0, 212, 255, 0.35);
  --border-h: rgba(0, 212, 255, 0.85);
  --text:     #f0f8ff;
  --muted:    #92b8d8;
  --green:    #00ffcc;
  --red:      #ff4070;
  --blue:     #38c8ff;
  --yellow:   #ffcc00;
  --cyan:     #00f5ff;
  --purple:   #d078ff;
  --radius:   10px;
}

body {
  font-family: 'Segoe UI', system-ui, sans-serif;
  background-color: var(--bg);
  background-image:
    linear-gradient(rgba(0, 212, 255, 0.11) 1px, transparent 1px),
    linear-gradient(90deg, rgba(0, 212, 255, 0.11) 1px, transparent 1px);
  background-size: 44px 44px;
  color: var(--text);
  min-height: 100vh;
  padding: 0.6rem 1.5rem 0.6rem;
}

/* Floating ambient light orbs */
.orb {
  position: fixed;
  border-radius: 50%;
  filter: blur(70px);
  pointer-events: none;
  z-index: -1;
}
.orb-1 {
  width: 620px; height: 620px;
  top: -120px; left: -100px;
  background: radial-gradient(circle, rgba(30, 100, 255, 0.40) 0%, transparent 68%);
  animation: orb-drift1 16s ease-in-out infinite alternate;
}
.orb-2 {
  width: 540px; height: 540px;
  bottom: -100px; right: -100px;
  background: radial-gradient(circle, rgba(160, 0, 255, 0.38) 0%, transparent 68%);
  animation: orb-drift2 20s ease-in-out infinite alternate;
}
.orb-3 {
  width: 460px; height: 460px;
  top: 25%; right: -80px;
  background: radial-gradient(circle, rgba(0, 220, 200, 0.30) 0%, transparent 68%);
  animation: orb-drift3 13s ease-in-out infinite alternate;
}
@keyframes orb-drift1 {
  from { transform: translate(0,0) scale(1); }
  to   { transform: translate(90px, 110px) scale(1.18); }
}
@keyframes orb-drift2 {
  from { transform: translate(0,0) scale(1); }
  to   { transform: translate(-100px,-90px) scale(1.22); }
}
@keyframes orb-drift3 {
  from { transform: translate(0,0) scale(1); }
  to   { transform: translate(-65px, 90px) scale(1.12); }
}

header {
  display: flex;
  align-items: center;
  gap: 1rem;
  margin-bottom: 0.6rem;
  flex-wrap: wrap;
  position: relative;
  z-index: 1;
}
header h1 { font-size: 1.25rem; font-weight: 800; letter-spacing: -.02em; }
header h1 .grad {
  background: linear-gradient(120deg, var(--cyan) 0%, #ff60e0 50%, var(--purple) 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  filter: drop-shadow(0 0 24px rgba(0, 238, 255, 0.8));
}
header .subtitle {
  color: var(--muted);
  font-size: .70rem;
  margin-top: .15rem;
  font-family: 'Courier New', monospace;
  letter-spacing: .04em;
}
.refresh-btn {
  margin-left: auto;
  background: rgba(0, 212, 255, 0.07);
  border: 1px solid rgba(0, 212, 255, 0.3);
  color: var(--cyan);
  border-radius: 6px;
  padding: .35rem .8rem;
  font-size: .78rem;
  cursor: pointer;
  transition: all .2s;
  font-family: 'Courier New', monospace;
}
.refresh-btn:hover {
  background: rgba(0, 212, 255, 0.14);
  border-color: rgba(0, 212, 255, 0.7);
  box-shadow: 0 0 20px rgba(0, 212, 255, 0.35);
  color: #fff;
}

/* Section label */
.section-label {
  grid-column: 1 / -1;
  font-size: .67rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .12em;
  color: var(--muted);
  font-family: 'Courier New', monospace;
  padding: .3rem 0 .15rem;
  border-bottom: 1px solid rgba(0, 212, 255, 0.15);
  margin-bottom: .1rem;
}
.section-label:first-child { padding-top: 0; }

/* Grid */
.grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 0.6rem;
  position: relative;
  z-index: 1;
}
@media (max-width: 900px) {
  .grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 480px) {
  .grid { grid-template-columns: 1fr; }
}

/* Cards */
.card {
  background: var(--surface);
  backdrop-filter: blur(22px);
  -webkit-backdrop-filter: blur(22px);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 0.7rem 1rem;
  display: flex;
  flex-direction: column;
  gap: .45rem;
  position: relative;
  overflow: hidden;
  transition: border-color .25s, box-shadow .25s, transform .2s;
  box-shadow: 0 4px 40px rgba(0,0,0,0.28), inset 0 1px 0 rgba(255,255,255,0.12);
  animation: card-in .4s ease both;
}
/* Network group: children 2–5 (child 1 = section label) */
.card:nth-child(2)  { animation-delay: .00s; }
.card:nth-child(3)  { animation-delay: .07s; }
.card:nth-child(4)  { animation-delay: .14s; }
.card:nth-child(5)  { animation-delay: .21s; }
/* Infrastructure group: children 7–10 (child 6 = section label) */
.card:nth-child(7)  { animation-delay: .28s; }
.card:nth-child(8)  { animation-delay: .35s; }
.card:nth-child(9)  { animation-delay: .42s; }
.card:nth-child(10) { animation-delay: .49s; }
/* Energy & Smart Home: children 12–14 (child 11 = section label) */
.card:nth-child(12) { animation-delay: .56s; }
.card:nth-child(13) { animation-delay: .63s; }
.card:nth-child(14) { animation-delay: .70s; }
@keyframes card-in {
  from { opacity: 0; transform: translateY(12px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* Shine sweep */
.card::before {
  content: '';
  position: absolute;
  top: 0; left: -80%;
  width: 55%;
  height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,0.06), transparent);
  transform: skewX(-15deg);
  pointer-events: none;
}
/* Top glow edge */
.card::after {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 1px;
  background: linear-gradient(90deg, transparent, rgba(0, 212, 255, 0.6), transparent);
  opacity: 0;
  transition: opacity .25s;
}
.card:hover {
  border-color: var(--border-h);
  box-shadow: 0 0 45px rgba(0, 212, 255, 0.14), 0 8px 36px rgba(0,0,0,0.35), inset 0 1px 0 rgba(255,255,255,0.12);
  transform: translateY(-2px);
}
.card:hover::after { opacity: 1; }
.card:hover::before { animation: shine-sweep .7s ease forwards; }
@keyframes shine-sweep {
  from { left: -80%; }
  to   { left: 140%; }
}

.card-head { display: flex; align-items: flex-start; gap: .75rem; }
.card-icon {
  width: 30px; height: 30px;
  border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.1rem; flex-shrink: 0;
}
.card-title { font-size: 0.85rem; font-weight: 600; color: #eaf6ff; }
.card-sub   { font-size: .64rem; color: var(--muted); margin-top: .12rem; font-family: 'Courier New', monospace; letter-spacing: .03em; }

.badge {
  display: inline-flex;
  align-items: center;
  gap: .3rem;
  border-radius: 20px;
  padding: .16rem .55rem;
  font-size: .67rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .07em;
  font-family: 'Courier New', monospace;
  white-space: nowrap;
}
.badge-online  { background: rgba(0, 255, 204, 0.14); color: var(--green); border: 1px solid rgba(0, 255, 204, 0.5); box-shadow: 0 0 20px rgba(0, 255, 204, 0.4), inset 0 0 8px rgba(0, 255, 204, 0.1); }
.badge-offline { background: rgba(255, 64, 112, 0.1); color: var(--red);   border: 1px solid rgba(255, 64, 112, 0.38); box-shadow: 0 0 16px rgba(255, 64, 112, 0.25); }
.badge-loading { background: rgba(0, 212, 255, 0.06); color: var(--muted); border: 1px solid rgba(0, 212, 255, 0.18); }
.badge::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor; flex-shrink: 0; }
.badge-online::before  { animation: dot-pulse 2s ease-in-out infinite; }
.badge-offline::before { animation: dot-pulse .8s ease-in-out infinite; }
@keyframes dot-pulse {
  0%, 100% { opacity: 1; }
  50%       { opacity: .15; }
}

/* Stats row */
.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(62px, 1fr)); gap: .35rem; }
.stat-label { font-size: .57rem; color: var(--muted); text-transform: uppercase; letter-spacing: .07em; font-family: 'Courier New', monospace; }
.stat-value { font-size: 0.88rem; font-weight: 700; margin-top: .15rem; color: #fff; font-family: 'Courier New', monospace; text-shadow: 0 0 18px rgba(0, 238, 255, 0.75); }
.stat-value.small { font-size: .75rem; }

/* Progress bar */
.bar-wrap { margin-top: .1rem; }
.bar-label { display: flex; justify-content: space-between; font-size: .66rem; color: var(--muted); margin-bottom: .3rem; font-family: 'Courier New', monospace; }
.bar { height: 3px; background: rgba(0, 212, 255, 0.1); border-radius: 2px; overflow: hidden; }
.bar-fill { height: 100%; border-radius: 2px; transition: width .6s cubic-bezier(.4,0,.2,1); }
.bar-fill.green  { background: linear-gradient(90deg, var(--green), var(--blue)); box-shadow: 0 0 10px rgba(0, 255, 184, 0.65); }
.bar-fill.yellow { background: linear-gradient(90deg, var(--yellow), #ff8800);   box-shadow: 0 0 10px rgba(255, 204, 0, 0.55); }
.bar-fill.red    { background: linear-gradient(90deg, var(--red), #ff0050);      box-shadow: 0 0 10px rgba(255, 64, 112, 0.65); }

/* Footer */
.card-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: auto;
  padding-top: .35rem;
  border-top: 1px solid rgba(0, 212, 255, 0.1);
  font-size: .68rem;
}
.card-footer a { color: var(--cyan); text-decoration: none; transition: all .18s; font-family: 'Courier New', monospace; font-size: .66rem; }
.card-footer a:hover { color: #fff; text-shadow: 0 0 12px var(--cyan); }
.card-ip { color: var(--muted); font-size: .60rem; font-family: 'Courier New', monospace; }

/* Skeleton */
.skeleton { height: .8rem; background: rgba(0, 212, 255, 0.09); border-radius: 3px; animation: pulse 1.5s ease-in-out infinite; }
@keyframes pulse {
  0%, 100% { opacity: 1; }
  50%       { opacity: .18; }
}

/* Icon backgrounds */
.icon-mt   { background: rgba(0, 120, 240, 0.24); border: 1px solid rgba(0, 120, 240, 0.42); }
.icon-ph   { background: rgba(0, 200, 140, 0.24); border: 1px solid rgba(0, 200, 140, 0.42); }
.icon-sy   { background: rgba(120, 50, 240, 0.24); border: 1px solid rgba(120, 50, 240, 0.42); }
.icon-rt   { background: rgba(0, 210, 210, 0.22);  border: 1px solid rgba(0, 210, 210, 0.4); }
.icon-cam  { background: rgba(240, 110, 0, 0.24);  border: 1px solid rgba(240, 110, 0, 0.42); }
.icon-wifi { background: rgba(0, 210, 90, 0.22);   border: 1px solid rgba(0, 210, 90, 0.4); }
.icon-prt  { background: rgba(160, 160, 180, 0.2); border: 1px solid rgba(160, 160, 180, 0.35); }
.icon-ev   { background: rgba(220, 50, 30, 0.22);  border: 1px solid rgba(220, 50, 30, 0.42); }
.icon-son  { background: rgba(0, 60, 200, 0.22);   border: 1px solid rgba(0, 60, 200, 0.4); }
.icon-gh   { background: rgba(20, 140, 80, 0.22);  border: 1px solid rgba(20, 140, 80, 0.4); }
.icon-deye   { background: rgba(255, 160, 0, 0.22);  border: 1px solid rgba(255, 160, 0, 0.45); }
.icon-ts     { background: rgba(35,  110, 240, 0.22); border: 1px solid rgba(35, 110, 240, 0.45); }
.icon-spd    { background: rgba(0,   220, 110, 0.22); border: 1px solid rgba(0,  220, 110, 0.45); }

/* Wide card */
.card-wide { grid-column: span 2; }
@media (max-width: 480px) { .card-wide { grid-column: span 1; } }

/* Flow graph */
.flow-wrap {
  position: relative;
  height: 185px;
  margin: .1rem -.2rem 0;
}
.flow-svg {
  position: absolute;
  inset: 0;
  width: 100%; height: 100%;
  overflow: visible;
  pointer-events: none;
}
.flow-nodes {
  position: absolute;
  inset: 0;
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  grid-template-rows: 1fr 1fr 1fr;
  align-items: center;
  justify-items: center;
}
.fnode {
  background: rgba(8, 20, 42, 0.92);
  border: 1px solid rgba(0, 212, 255, 0.25);
  border-radius: 10px;
  padding: .3rem .45rem;
  text-align: center;
  min-width: 66px;
  position: relative;
  z-index: 1;
}
.fnode-icon { font-size: 1.1rem; line-height: 1.1; }
.fnode-val  { font-size: .78rem; font-weight: 700; font-family: 'Courier New', monospace; margin: .18rem 0 .1rem; }
.fnode-lbl  { font-size: .54rem; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; line-height: 1.3; }

@keyframes flow-dash { to { stroke-dashoffset: -22; } }
.flow-on  { animation: flow-dash .65s linear infinite; }
.flow-rev { animation: flow-dash .65s linear infinite reverse; }

/* Free-form card layout */
.card { cursor: grab; user-select: none; }
.card.is-dragging {
  opacity: 0.88;
  cursor: grabbing;
  box-shadow: 0 16px 60px rgba(0,212,255,0.28), 0 0 0 2px rgba(0,245,255,0.6) !important;
}
.resize-handle {
  position: absolute;
  bottom: 0; right: 0;
  width: 20px; height: 20px;
  cursor: se-resize;
  z-index: 3;
  opacity: 0;
  transition: opacity .15s;
}
.resize-handle::after {
  content: '';
  position: absolute;
  bottom: 4px; right: 4px;
  width: 9px; height: 9px;
  border-right: 2px solid var(--cyan);
  border-bottom: 2px solid var(--cyan);
  border-radius: 0 0 3px 0;
}
.card:hover .resize-handle { opacity: 1; }
</style>
</head>
<body>

<header>
  <div>
    <h1>🌐 <span class="grad">JG-Space Network</span></h1>
    <div class="subtitle">Infrastructure portal — <span id="last-refresh">loading…</span></div>
  </div>
  <button class="refresh-btn" onclick="refreshAll()">↺ Refresh</button>
</header>

<div class="grid" id="grid">

  <div class="section-label">⬡ Network</div>

  <!-- MikroTik -->
  <div class="card" id="card-mikrotik">
    <div class="card-head">
      <div class="card-icon icon-mt">🔀</div>
      <div style="flex:1">
        <div class="card-title">MikroTik</div>
        <div class="card-sub">RouterOS · 192.168.50.251</div>
      </div>
      <span class="badge badge-loading" id="badge-mikrotik">Checking</span>
    </div>
    <div id="body-mikrotik"><div class="skeleton" style="width:80%"></div></div>
    <div class="card-footer">
      <a href="portal.php?open=mikrotik" target="_blank">Open WebFig →</a>
      <span class="card-ip">:80 · auto-login</span>
    </div>
  </div>

  <!-- Ruijie Router -->
  <div class="card" id="card-router">
    <div class="card-head">
      <div class="card-icon icon-rt">📡</div>
      <div style="flex:1">
        <div class="card-title">Ruijie Router</div>
        <div class="card-sub">RG-EG710-XS · 192.168.50.1</div>
      </div>
      <span class="badge badge-loading" id="badge-router">Checking</span>
    </div>
    <div id="body-router"><div class="skeleton" style="width:60%"></div></div>
    <div class="card-footer">
      <a href="portal.php?open=router" target="_blank">Open Admin →</a>
      <span class="card-ip">:443 · auto-login</span>
    </div>
  </div>

  <!-- Pi-hole 1 -->
  <div class="card" id="card-pihole">
    <div class="card-head">
      <div class="card-icon icon-ph">🕳️</div>
      <div style="flex:1">
        <div class="card-title">Pi-hole 1</div>
        <div class="card-sub">DNS Ad Blocker · 192.168.50.247</div>
      </div>
      <span class="badge badge-loading" id="badge-pihole">Checking</span>
    </div>
    <div id="body-pihole"><div class="skeleton" style="width:80%"></div></div>
    <div class="card-footer">
      <span>
        <a href="http://192.168.50.247/admin/" target="_blank">Admin →</a>
        &nbsp;·&nbsp;
        <a href="pihole.php?host=1" target="_blank">Gravity</a>
      </span>
      <span class="card-ip">:80/admin</span>
    </div>
  </div>

  <!-- Pi-hole 2 -->
  <div class="card" id="card-pihole2">
    <div class="card-head">
      <div class="card-icon icon-ph">🕳️</div>
      <div style="flex:1">
        <div class="card-title">Pi-hole 2</div>
        <div class="card-sub">DNS Ad Blocker · 192.168.50.248</div>
      </div>
      <span class="badge badge-loading" id="badge-pihole2">Checking</span>
    </div>
    <div id="body-pihole2"><div class="skeleton" style="width:80%"></div></div>
    <div class="card-footer">
      <span>
        <a href="http://192.168.50.248/admin/" target="_blank">Admin →</a>
        &nbsp;·&nbsp;
        <a href="pihole.php?host=2" target="_blank">Gravity</a>
      </span>
      <span class="card-ip">:80/admin</span>
    </div>
  </div>

  <div class="section-label">⬡ Infrastructure</div>

  <!-- Synology -->
  <div class="card" id="card-synology">
    <div class="card-head">
      <div class="card-icon icon-sy">🗄️</div>
      <div style="flex:1">
        <div class="card-title">Synology NAS</div>
        <div class="card-sub">Syno1522 · 192.168.50.99</div>
      </div>
      <span class="badge badge-loading" id="badge-synology">Checking</span>
    </div>
    <div id="body-synology"><div class="skeleton" style="width:80%"></div></div>
    <div class="card-footer">
      <a href="portal.php?open=synology" target="_blank">Open DSM →</a>
      <span class="card-ip">:5000 · auto-login</span>
    </div>
  </div>

  <!-- Cameras -->
  <div class="card" id="card-cameras">
    <div class="card-head">
      <div class="card-icon icon-cam">📷</div>
      <div style="flex:1">
        <div class="card-title">Dahua Cameras</div>
        <div class="card-sub">NVR · 192.168.50.151–158</div>
      </div>
      <span class="badge badge-loading" id="badge-cameras">Checking</span>
    </div>
    <div id="body-cameras"><div class="skeleton" style="width:60%"></div></div>
    <div class="card-footer">
      <a href="nvr.php" target="_blank">Live View →</a>
      <span class="card-ip">8 cameras</span>
    </div>
  </div>

  <!-- Tesla Wall Connector -->
  <div class="card" id="card-tesla_charger">
    <div class="card-head">
      <div class="card-icon icon-ev">🔌</div>
      <div style="flex:1">
        <div class="card-title">Tesla Wall Connector</div>
        <div class="card-sub">EV Charger · 192.168.50.49</div>
      </div>
      <span class="badge badge-loading" id="badge-tesla_charger">Checking</span>
    </div>
    <div id="body-tesla_charger"><div class="skeleton" style="width:80%"></div></div>
    <div class="card-footer">
      <a href="http://192.168.50.49/" target="_blank">Open Dashboard →</a>
      <span class="card-ip">Gen 3 · fw 26.2</span>
    </div>
  </div>

  <div class="section-label">⬡ Energy &amp; Smart Home</div>

  <!-- Deye Inverter -->
  <div class="card card-wide" id="card-deye">
    <div class="card-head">
      <div class="card-icon icon-deye">☀️</div>
      <div style="flex:1">
        <div class="card-title">Deye Inverter</div>
        <div class="card-sub">Solar · deyecloud.com · Station <?= DEYE_STATION ?></div>
      </div>
      <span class="badge badge-loading" id="badge-deye">Checking</span>
    </div>
    <div id="body-deye"><div class="skeleton" style="width:80%"></div></div>
    <div class="card-footer">
      <a href="https://www.deyecloud.com/station/new-main?id=<?= DEYE_STATION ?>" target="_blank">Open Cloud →</a>
      <span class="card-ip">eu1 cloud</span>
    </div>
  </div>

  <!-- Tailscale -->
  <div class="card card-wide" id="card-tailscale">
    <div class="card-head">
      <div class="card-icon icon-ts">🔐</div>
      <div style="flex:1">
        <div class="card-title">Tailscale</div>
        <div class="card-sub">VPN Mesh · tailnet-4abb</div>
      </div>
      <span class="badge badge-loading" id="badge-tailscale">Checking</span>
    </div>
    <div id="body-tailscale"><div class="skeleton" style="width:80%"></div></div>
  </div>

  <div class="section-label">⬡ Internet Speed</div>

  <!-- Speedtest -->
  <div class="card card-wide" id="card-speedtest">
    <div class="card-head">
      <div class="card-icon icon-spd">⚡</div>
      <div style="flex:1">
        <div class="card-title">Speedtest</div>
        <div class="card-sub">Ookla · download / upload / latency</div>
      </div>
      <span class="badge badge-loading" id="badge-speedtest">Loading</span>
    </div>
    <div id="body-speedtest"><div class="skeleton" style="width:80%"></div></div>
    <div class="card-footer">
      <a href="#" id="speedtest-run-btn" onclick="runSpeedtest();return false;">▶ Run Test</a>
      <span class="card-ip" id="speedtest-age">—</span>
    </div>
  </div>

  <div class="section-label">⬡ Camera Feed</div>

  <!-- Camera 7 live snapshot -->
  <div class="card card-wide" id="card-cam157">
    <div class="card-head">
      <div class="card-icon icon-cam">📷</div>
      <div style="flex:1">
        <div class="card-title">Camera 7</div>
        <div class="card-sub">Dahua · 192.168.50.157 · refreshes every 5 s</div>
      </div>
      <span class="badge badge-loading" id="badge-cam157">Loading</span>
    </div>
    <div style="position:relative;background:#000;border-radius:8px;overflow:hidden;aspect-ratio:16/9;margin-top:.2rem">
      <img id="cam157-img" style="width:100%;height:100%;object-fit:contain;display:block;transition:opacity .3s" src="" alt="Camera feed">
      <div id="cam157-overlay" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.55);font-size:.85rem;color:var(--muted);font-family:'Courier New',monospace">Loading…</div>
    </div>
    <div class="card-footer">
      <a href="#" onclick="triggerCam157();return false;">↺ Refresh now</a>
      <span class="card-ip" id="cam157-ts">—</span>
    </div>
  </div>


</div>

<script>
const checks = ['mikrotik', 'pihole', 'pihole2', 'synology', 'router', 'cameras', 'tesla_charger', 'deye', 'tailscale', 'speedtest'];

function pct(a, b) { return b > 0 ? Math.round(a / b * 100) : 0; }

function barColor(p) {
  if (p >= 85) return 'red';
  if (p >= 65) return 'yellow';
  return 'green';
}

function bar(label, used, total, unit) {
  const p = pct(used, total);
  return `<div class="bar-wrap">
    <div class="bar-label"><span>${label}</span><span>${used}${unit} / ${total}${unit} (${p}%)</span></div>
    <div class="bar"><div class="bar-fill ${barColor(p)}" style="width:${p}%"></div></div>
  </div>`;
}

function statBox(label, value, small=false) {
  return `<div class="stat">
    <div class="stat-label">${label}</div>
    <div class="stat-value${small?' small':''}">${value}</div>
  </div>`;
}

function setBadge(id, online, label) {
  const el = document.getElementById('badge-' + id);
  el.className = 'badge ' + (online ? 'badge-online' : 'badge-offline');
  el.textContent = label ?? (online ? 'Online' : 'Offline');
}

function setBody(id, html) {
  document.getElementById('body-' + id).innerHTML = html;
}

function fmtUptime(s) {
  // RouterOS format: "3d6h33m56s"
  return s.replace(/(\d+)d/, '$1d ').replace(/(\d+)h/, '$1h ').replace(/(\d+)m.*/, '$1m').trim();
}

function fmtNum(n) { return Number(n).toLocaleString(); }

function fetchCheck(name) {
  return fetch(`portal.php?check=${name}`)
    .then(r => r.json())
    .catch(() => ({ online: false, error: 'fetch failed' }));
}

function renderMikrotik(d) {
  if (!d.online) { setBadge('mikrotik', false); setBody('mikrotik', '<span style="color:var(--muted);font-size:.85rem">Unreachable</span>'); return; }
  setBadge('mikrotik', true);
  const memPct = pct(d.mem_tot - d.mem_free, d.mem_tot);
  const memUsed = Math.round((d.mem_tot - d.mem_free) / 1048576);
  const memTot  = Math.round(d.mem_tot / 1048576);
  setBody('mikrotik', `
    <div class="stats">
      ${statBox('Version', d.version, true)}
      ${statBox('Uptime', fmtUptime(d.uptime), true)}
      ${statBox('CPU', d.cpu + '%')}
      ${statBox('Leases', d.leases)}
    </div>
    ${bar('Memory', memUsed, memTot, ' MB')}
  `);
}

function renderPihole(d, id='pihole') {
  if (!d.online) { setBadge(id, false); setBody(id, '<span style="color:var(--muted);font-size:.85rem">Unreachable</span>'); return; }
  const label = d.blocking ? 'Blocking' : 'Paused';
  setBadge(id, d.blocking, label);
  const bPct = d.pct;
  setBody(id, `
    <div class="stats">
      ${statBox('Blocked', fmtNum(d.domains), true)}
      ${statBox('Queries', fmtNum(d.total))}
      ${statBox('Clients', d.clients)}
      ${statBox('Block %', d.pct + '%')}
    </div>
    <div class="bar-wrap">
      <div class="bar-label"><span>Blocked queries</span><span>${fmtNum(d.blocked)} / ${fmtNum(d.total)}</span></div>
      <div class="bar"><div class="bar-fill green" style="width:${Math.min(bPct,100)}%"></div></div>
    </div>
    <div style="font-size:.72rem;color:var(--muted)">Gravity updated: ${d.gravity_updated}</div>
  `);
}

function renderSynology(d) {
  if (!d.online) { setBadge('synology', false); setBody('synology', '<span style="color:var(--muted);font-size:.85rem">Unreachable</span>'); return; }
  setBadge('synology', true);
  let storageHtml = '';
  if (d.vol_tot) {
    const usedGB  = (d.vol_use / 1e9).toFixed(1);
    const totalGB = (d.vol_tot / 1e9).toFixed(1);
    storageHtml = bar('Storage', usedGB, totalGB, ' GB');
  }
  const cpuHtml  = d.cpu !== null ? statBox('CPU', d.cpu + '%') : '';
  const tempHtml = d.sys_temp !== null ? statBox('Temp', d.sys_temp + '°C') : '';
  let tempBarHtml = '';
  if (d.sys_temp !== null) {
    const tp = Math.min(100, Math.round(d.sys_temp / 80 * 100));
    const tc = d.temp_warn ? 'red' : d.sys_temp >= 65 ? 'red' : d.sys_temp >= 50 ? 'yellow' : 'green';
    tempBarHtml = `<div class="bar-wrap">
      <div class="bar-label"><span>System Temp</span><span>${d.sys_temp}°C${d.temp_warn ? ' ⚠️' : ''}</span></div>
      <div class="bar"><div class="bar-fill ${tc}" style="width:${tp}%"></div></div>
    </div>`;
  }
  setBody('synology', `
    <div class="stats">
      ${statBox('Model', d.model, true)}
      ${statBox('DSM', d.dsm_ver, true)}
      ${cpuHtml}
      ${tempHtml}
    </div>
    ${storageHtml}
    ${tempBarHtml}
  `);
}

function renderRouter(d) {
  if (!d.online) { setBadge('router', false); setBody('router', '<span style="color:var(--muted);font-size:.85rem">Unreachable</span>'); return; }
  setBadge('router', true);
  setBody('router', `
    <div class="stats">
      ${statBox('Model', d.model, true)}
      ${statBox('Uptime', d.uptime, true)}
    </div>
  `);
}

function renderCameras(d) {
  if (!d.online) { setBadge('cameras', false); setBody('cameras', '<span style="color:var(--muted);font-size:.85rem">All cameras offline</span>'); return; }
  setBadge('cameras', true, `${d.up}/${d.total} Online`);
  const dots = Object.entries(d.cameras).map(([ip, up]) => {
    const n = ip.split('.').pop();
    return `<span title="Camera ${n-150} — ${ip}" style="display:inline-flex;align-items:center;gap:.25rem;font-size:.78rem;color:${up?'var(--green)':'var(--red)'}">
      <span style="width:8px;height:8px;border-radius:50%;background:currentColor;display:inline-block"></span>Cam ${n-150}
    </span>`;
  }).join('');
  setBody('cameras', `<div style="display:flex;flex-wrap:wrap;gap:.5rem .75rem">${dots}</div>`);
}

function renderTesla_charger(d) {
  if (!d.online) { setBadge('tesla_charger', false); setBody('tesla_charger', '<span style="color:var(--muted);font-size:.85rem">Unreachable</span>'); return; }
  const charging = d.state === 'Charging';
  const connected = d.vehicle_connected;
  const label = d.state;
  const badgeOn = charging || connected;
  setBadge('tesla_charger', badgeOn, label);
  const pBarColor = charging ? 'green' : 'yellow';
  const tempPct = Math.min(Math.round(d.temp_c / 80 * 100), 100);
  setBody('tesla_charger', `
    <div class="stats">
      ${statBox('Grid', d.grid_v + ' V')}
      ${statBox('Session', d.session_kwh + ' kWh')}
      ${statBox('Total', d.total_kwh + ' kWh', true)}
      ${statBox('Uptime', d.uptime)}
    </div>
    <div class="bar-wrap">
      <div class="bar-label"><span>Board Temp</span><span>${d.temp_c}°C</span></div>
      <div class="bar"><div class="bar-fill ${tempPct > 70 ? 'red' : tempPct > 50 ? 'yellow' : 'green'}" style="width:${tempPct}%"></div></div>
    </div>
    <div style="font-size:.72rem;color:var(--muted)">${d.charge_starts} charge sessions total · ${charging ? '⚡ Charging now' : (connected ? '🔗 Vehicle connected' : 'No vehicle')}</div>
  `);
}

function renderDeye(d) {
  if (!d.online) {
    setBadge('deye', false);
    setBody('deye', `<span style="color:var(--muted);font-size:.85rem">${d.error ?? 'Unreachable'}</span>`);
    return;
  }

  const solarW = Math.max(0, d.generation_w ?? 0);
  const gridW  = d.grid_w  ?? 0;   // + = importing, - = exporting
  const batW   = d.battery_w ?? 0; // + = charging,  - = discharging
  const loadW  = Math.max(0, d.consumption_w ?? 0);
  const soc    = d.battery_soc ?? 0;
  const dayKwh = d.day_kwh  !== null && d.day_kwh  !== undefined ? Number(d.day_kwh).toFixed(1)  : '—';
  const totKwh = d.total_kwh !== null && d.total_kwh !== undefined ? Number(d.total_kwh).toFixed(0) : '—';

  const fmtW = w => {
    const a = Math.abs(w);
    return a >= 1000 ? (a / 1000).toFixed(2) + ' kW' : Math.round(a) + ' W';
  };

  const generating = solarW > 20;
  setBadge('deye', generating, generating ? 'Generating' : `Bat ${soc}%`);

  // SVG viewBox 560×240, node centers:
  // solar(90,48)  grid(470,48)  inverter(280,120)  battery(90,192)  load(470,192)
  const nc = { s:[90,48], g:[470,48], i:[280,120], b:[90,192], l:[470,192] };

  const svgLine = (ax, ay, bx, by, active, col, reverse) => {
    const dx = bx-ax, dy = by-ay, len = Math.hypot(dx, dy);
    const nx = dx/len, ny = dy/len, gap = 44;
    const x1 = (ax + nx*gap).toFixed(1), y1 = (ay + ny*gap).toFixed(1);
    const x2 = (bx - nx*gap).toFixed(1), y2 = (by - ny*gap).toFixed(1);
    const cls = active ? (reverse ? 'flow-rev' : 'flow-on') : '';
    const op  = active ? 0.85 : 0.2;
    return `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}"
      stroke="${col}" stroke-width="2.5" stroke-dasharray="8 5"
      stroke-linecap="round" opacity="${op}" class="${cls}"/>`;
  };

  // Determine actual flow directions for each segment
  const [sx1,sy1,sx2,sy2] = [...nc.s, ...nc.i];                          // solar always → inverter
  const [gx1,gy1,gx2,gy2] = gridW >= 0 ? [...nc.g,...nc.i] : [...nc.i,...nc.g]; // import vs export
  const [bx1,by1,bx2,by2] = batW  < 0  ? [...nc.i,...nc.b] : [...nc.b,...nc.i]; // negative=charging(inv→bat), positive=discharging(bat→inv)
  const [lx1,ly1,lx2,ly2] = [...nc.i, ...nc.l];                          // inverter always → load

  const svg = `<svg class="flow-svg" viewBox="0 0 560 240" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg">
    ${svgLine(sx1,sy1,sx2,sy2, solarW>20,  '#fbbf24', false)}
    ${svgLine(gx1,gy1,gx2,gy2, Math.abs(gridW)>20, '#38bdf8', false)}
    ${svgLine(bx1,by1,bx2,by2, Math.abs(batW)>20,  '#a78bfa', false)}
    ${svgLine(lx1,ly1,lx2,ly2, loadW>20,   '#34d399', false)}
  </svg>`;

  const node = (area, icon, val, lbl, col) =>
    `<div class="fnode" style="grid-area:${area};border-color:${col}55">
      <div class="fnode-icon">${icon}</div>
      <div class="fnode-val" style="color:${col};text-shadow:0 0 14px ${col}66">${val}</div>
      <div class="fnode-lbl">${lbl}</div>
    </div>`;

  const gridLbl = gridW > 20 ? 'Importing' : gridW < -20 ? 'Exporting' : 'On grid';
  const batLbl  = batW  < -20 ? '↑ Charging' : batW > 20 ? '↓ Discharging' : 'Idle';

  // Temperature helpers
  const fmtTemp = t => t !== null && t !== undefined ? Number(t).toFixed(1) + '°C' : '—';
  const tempColor = t => t === null || t === undefined ? 'green' : t >= 65 ? 'red' : t >= 50 ? 'yellow' : 'green';
  const tempPct   = t => t === null || t === undefined ? 0 : Math.min(100, Math.round(t / 80 * 100));

  const tempBar = (label, t) => {
    if (t === null || t === undefined) return '';
    return `<div class="bar-wrap">
      <div class="bar-label"><span>${label}</span><span>${fmtTemp(t)}</span></div>
      <div class="bar"><div class="bar-fill ${tempColor(t)}" style="width:${tempPct(t)}%"></div></div>
    </div>`;
  };

  const hasTempData = [d.temp_bat, d.temp_ac, d.temp_dc].some(v => v !== null && v !== undefined);
  const tempStatsHtml = hasTempData ? `
    <div style="margin-top:.4rem;padding-top:.35rem;border-top:1px solid rgba(0,212,255,0.12)">
      <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);font-family:'Courier New',monospace;margin-bottom:.35rem">Temperatures</div>
      <div class="stats" style="grid-template-columns:repeat(3,1fr);margin-bottom:.35rem">
        ${d.temp_ac  !== null && d.temp_ac  !== undefined ? statBox('Inverter AC',  fmtTemp(d.temp_ac))  : ''}
        ${d.temp_dc  !== null && d.temp_dc  !== undefined ? statBox('Inverter DC',  fmtTemp(d.temp_dc))  : ''}
        ${d.temp_bat !== null && d.temp_bat !== undefined ? statBox('Battery',      fmtTemp(d.temp_bat)) : ''}
      </div>
      ${tempBar('AC', d.temp_ac)}
      ${tempBar('DC', d.temp_dc)}
      ${tempBar('Bat', d.temp_bat)}
    </div>` : '';

  // DC string rows (PV1, PV2, PV3)
  const pvs = d.pv ?? [];
  const pvHtml = pvs.length ? `
    <div style="margin-top:.4rem;padding-top:.35rem;border-top:1px solid rgba(0,212,255,0.12)">
      <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);font-family:'Courier New',monospace;margin-bottom:.35rem">DC Strings</div>
      <div style="display:grid;grid-template-columns:auto 1fr 1fr 1fr;gap:.2rem .5rem;align-items:center">
        <div style="font-size:.57rem;color:var(--muted);font-family:'Courier New',monospace"></div>
        <div style="font-size:.57rem;color:var(--muted);font-family:'Courier New',monospace;text-align:right">Voltage</div>
        <div style="font-size:.57rem;color:var(--muted);font-family:'Courier New',monospace;text-align:right">Current</div>
        <div style="font-size:.57rem;color:var(--muted);font-family:'Courier New',monospace;text-align:right">Power</div>
        ${pvs.map(pv => `
        <div style="font-size:.72rem;font-weight:700;color:#fbbf24;font-family:'Courier New',monospace">PV${pv.n}</div>
        <div style="font-size:.8rem;font-weight:700;color:#fff;font-family:'Courier New',monospace;text-align:right;text-shadow:0 0 12px #fbbf2466">${pv.v.toFixed(1)} <span style="font-size:.6rem;color:var(--muted)">V</span></div>
        <div style="font-size:.8rem;font-weight:700;color:#fff;font-family:'Courier New',monospace;text-align:right;text-shadow:0 0 12px #fbbf2466">${pv.a.toFixed(2)} <span style="font-size:.6rem;color:var(--muted)">A</span></div>
        <div style="font-size:.8rem;font-weight:700;color:#fbbf24;font-family:'Courier New',monospace;text-align:right;text-shadow:0 0 14px #fbbf2488">${pv.w} <span style="font-size:.6rem;color:var(--muted)">W</span></div>`).join('')}
      </div>
    </div>` : '';

  // ── Battery 1 & 2 detail cards ─────────────────────────────────────────────
  const socBarCol = v => {
    if (v === null || v === undefined) return '#4a6878';
    const n = Number(v);
    return n >= 50 ? '#00ffcc' : n >= 20 ? '#ffcc00' : '#ff4070';
  };
  const fmtCycles = v => v !== null && v !== undefined ? Math.round(Number(v)).toLocaleString() : null;

  const batStatusLabel = v => {
    if (v === null || v === undefined) return null;
    return ({0:'Standby', 1:'Charging', 2:'Discharging', 3:'Fault', 4:'Hibernating', 11:'Idle'})[Math.round(Number(v))] ?? ('State '+Math.round(Number(v)));
  };

  const miniStat = (lbl, val, col) => val === null || val === undefined ? '' :
    `<div style="text-align:center;background:rgba(0,0,0,.2);border-radius:6px;padding:.28rem .3rem">
      <div style="font-size:.5rem;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;font-family:'Courier New',monospace">${lbl}</div>
      <div style="font-size:.75rem;font-weight:700;font-family:'Courier New',monospace;margin-top:.1rem;color:${col||'#fff'}">${val}</div>
    </div>`;

  const batMiniCard = (label, b) => {
    const hasMetrics = b && Object.entries(b).some(([k,v]) => k !== 'sn' && v !== null && v !== undefined);
    if (!hasMetrics) return `<div style="background:rgba(167,139,250,.05);border:1px solid rgba(167,139,250,.15);border-radius:8px;padding:.5rem .7rem;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:.2rem">
        <span style="font-size:.7rem;color:rgba(255,255,255,.2);font-family:'Courier New',monospace">${label} — no data</span>
        ${b && b.sn ? `<span style="font-size:.48rem;color:rgba(167,139,250,.3);font-family:'Courier New',monospace">${b.sn}</span>` : ''}
      </div>`;

    const bsoc   = b.soc   != null ? Math.round(Number(b.soc))       : null;
    const col    = socBarCol(bsoc);
    const bpow   = b.power != null ? Number(b.power)                 : null;
    const bdir   = bpow === null ? '' : bpow < -20 ? '↑ Chg' : bpow > 20 ? '↓ Dis' : 'Idle';
    const bvolt  = b.volt  != null ? Number(b.volt).toFixed(1)       : null;
    const bcurr  = b.curr  != null ? Number(b.curr).toFixed(1)       : null;
    const btemp  = b.temp  != null ? Number(b.temp).toFixed(1)       : null;
    const bah    = b.rated_ah != null ? Math.round(Number(b.rated_ah)) : null;
    const btempCol = btemp === null ? '#fff' : Number(btemp) >= 45 ? '#ff4070' : Number(btemp) >= 35 ? '#ffcc00' : '#00ffcc';

    return `<div style="background:rgba(167,139,250,.08);border:1px solid rgba(167,139,250,.28);border-radius:8px;padding:.5rem .65rem">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.3rem">
        <div>
          <div style="font-size:.62rem;font-weight:700;color:#a78bfa;font-family:'Courier New',monospace">🔋 ${label}</div>
          ${b.sn ? `<div style="font-size:.48rem;color:rgba(167,139,250,.45);font-family:'Courier New',monospace;margin-top:.05rem">${b.sn}</div>` : ''}
        </div>
        <div style="display:flex;align-items:center;gap:.35rem">
          ${bsoc !== null ? `<span style="font-size:.88rem;font-weight:800;font-family:'Courier New',monospace;color:${col};text-shadow:0 0 10px ${col}66">${bsoc}%</span>` : ''}
        </div>
      </div>
      ${bsoc !== null ? `
      <div style="margin-bottom:.35rem">
        <div style="height:5px;background:rgba(0,212,255,.1);border-radius:3px;overflow:hidden">
          <div style="width:${bsoc}%;height:100%;background:${col};box-shadow:0 0 8px ${col}77;border-radius:3px;transition:width .7s"></div>
        </div>
        ${bpow !== null ? `<div style="font-size:.54rem;color:var(--muted);font-family:'Courier New',monospace;margin-top:.12rem">${bdir} ${Math.abs(Math.round(bpow))} W</div>` : ''}
      </div>` : ''}
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(52px,1fr));gap:.28rem;margin-top:.2rem">
        ${bvolt !== null ? miniStat('Volt',     bvolt+' V',             '#fff')    : ''}
        ${bcurr !== null ? miniStat('Curr',     bcurr+' A',             '#fff')    : ''}
        ${btemp !== null ? miniStat('Temp',     btemp+'°C',             btempCol)  : ''}
        ${bpow  !== null ? miniStat('Power',    Math.abs(Math.round(bpow))+' W', '#fff') : ''}
        ${bah   !== null ? miniStat('Capacity', bah+' Ah',              '#a78bfa') : ''}
      </div>
    </div>`;
  };

  const bat1 = d.bat1 ?? {};
  const bat2 = d.bat2 ?? {};
  const hasBatDetail = [bat1, bat2].some(b => b && (b.sn || Object.entries(b).some(([k,v]) => k !== 'sn' && v != null)));

  // Energy throughput section
  const fmtKwh = v => v != null ? Number(v).toFixed(1)+' kWh' : null;
  const tchg   = fmtKwh(d.total_charge_kwh);
  const tdis   = fmtKwh(d.total_discharge_kwh);
  const dchg   = fmtKwh(d.daily_charge_kwh);
  const ddis   = fmtKwh(d.daily_discharge_kwh);
  const hasEnergy = tchg || tdis || dchg || ddis;

  const energyHtml = hasEnergy ? `
    <div style="margin-top:.4rem;padding-top:.35rem;border-top:1px solid rgba(0,212,255,0.12)">
      <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);font-family:'Courier New',monospace;margin-bottom:.35rem">Energy Throughput</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.3rem">
        ${tchg ? `<div style="background:rgba(0,0,0,.2);border-radius:6px;padding:.3rem .4rem">
          <div style="font-size:.48rem;color:var(--muted);font-family:'Courier New',monospace;text-transform:uppercase">Total Charged</div>
          <div style="font-size:.72rem;font-weight:700;color:#34d399;font-family:'Courier New',monospace;margin-top:.08rem">↑ ${tchg}</div>
        </div>` : ''}
        ${tdis ? `<div style="background:rgba(0,0,0,.2);border-radius:6px;padding:.3rem .4rem">
          <div style="font-size:.48rem;color:var(--muted);font-family:'Courier New',monospace;text-transform:uppercase">Total Discharged</div>
          <div style="font-size:.72rem;font-weight:700;color:#f87171;font-family:'Courier New',monospace;margin-top:.08rem">↓ ${tdis}</div>
        </div>` : ''}
        ${dchg ? `<div style="background:rgba(0,0,0,.2);border-radius:6px;padding:.3rem .4rem">
          <div style="font-size:.48rem;color:var(--muted);font-family:'Courier New',monospace;text-transform:uppercase">Today Charged</div>
          <div style="font-size:.72rem;font-weight:700;color:#34d399;font-family:'Courier New',monospace;margin-top:.08rem">↑ ${dchg}</div>
        </div>` : ''}
        ${ddis ? `<div style="background:rgba(0,0,0,.2);border-radius:6px;padding:.3rem .4rem">
          <div style="font-size:.48rem;color:var(--muted);font-family:'Courier New',monospace;text-transform:uppercase">Today Discharged</div>
          <div style="font-size:.72rem;font-weight:700;color:#f87171;font-family:'Courier New',monospace;margin-top:.08rem">↓ ${ddis}</div>
        </div>` : ''}
      </div>
    </div>` : '';

  const batDetailHtml = hasBatDetail ? `
    <div style="margin-top:.4rem;padding-top:.35rem;border-top:1px solid rgba(0,212,255,0.12)">
      <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);font-family:'Courier New',monospace;margin-bottom:.2rem">Battery Packs</div>
      <div style="font-size:.5rem;color:rgba(255,255,255,.25);font-family:'Courier New',monospace;margin-bottom:.35rem">Metrics reported as combined by inverter · Cycle count &amp; SoH not exposed by API</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.45rem">
        ${batMiniCard('Battery 1', bat1)}
        ${batMiniCard('Battery 2', bat2)}
      </div>
    </div>` : '';

  setBody('deye', `
    <div class="flow-wrap">
      ${svg}
      <div class="flow-nodes">
        ${node('1/1', '☀️',  fmtW(solarW),       'Today: '+dayKwh+' kWh', '#fbbf24')}
        ${node('1/3', '🔌',  fmtW(Math.abs(gridW)), gridLbl,              '#38bdf8')}
        ${node('2/2', '⚡',  'Inverter',           '',                    '#00d4ff')}
        ${node('3/1', '🔋',  soc+'%',              fmtW(batW)+' '+batLbl.split(' ')[0], '#a78bfa')}
        ${node('3/3', '🏠',  fmtW(loadW),          'Consumption',        '#34d399')}
      </div>
    </div>
    ${batDetailHtml}
    ${energyHtml}
    ${pvHtml}
    ${tempStatsHtml}
    <div style="font-size:.7rem;color:var(--muted);text-align:right;margin-top:.3rem">
      Total: ${totKwh} kWh${d.last_update ? ' · ' + d.last_update : ''}
    </div>
  `);
}

function copyIP(el, ip) {
  navigator.clipboard.writeText(ip).catch(() => {});
  const prev = el.style.color;
  el.style.transition = 'color .15s';
  el.style.color = '#fff';
  setTimeout(() => { el.style.color = prev; }, 600);
}

function ipSpan(ip, color) {
  return `<span onclick="copyIP(this,'${ip}')" title="Click to copy" style="font-family:'Courier New',monospace;font-size:.72rem;color:${color};cursor:pointer;white-space:nowrap" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">${ip}</span>`;
}

function renderTailscale(d) {
  if (!d.online && !(d.devices && d.devices.length)) {
    setBadge('tailscale', false);
    setBody('tailscale', `<span style="color:var(--muted);font-size:.85rem">${d.error ?? 'Unreachable'}</span>`);
    return;
  }
  setBadge('tailscale', d.up > 0, `${d.up}/${d.total} Online`);

  const osIcon = os => ({ linux:'🐧', iOS:'📱', windows:'🪟', android:'🤖', darwin:'🍎', macOS:'🍎' }[os] ?? '💻');

  const rows = d.devices.map(dev => {
    const col  = dev.online ? 'var(--green)' : 'rgba(255,255,255,0.22)';
    const glow = dev.online ? `;box-shadow:0 0 7px var(--green)` : '';
    const upd  = dev.updateAvail
      ? `<span style="color:var(--yellow);font-size:.6rem;margin-left:.25rem;font-family:'Courier New',monospace">↑upd</span>` : '';
    const age  = dev.online
      ? `<span style="color:var(--green);font-family:'Courier New',monospace;font-size:.65rem">● online</span>`
      : `<span style="color:var(--muted);font-family:'Courier New',monospace;font-size:.65rem">${dev.lastSeen}</span>`;

    const tsIp      = dev.ipv4 ? ipSpan(dev.ipv4, 'var(--cyan)') : '<span style="color:var(--muted);font-size:.72rem">—</span>';
    const privBlock = (dev.privateIps && dev.privateIps.length)
      ? dev.privateIps.map(ip => ipSpan(ip, 'var(--muted)')).join('<span style="color:rgba(255,255,255,0.2);margin:0 .2rem">·</span>')
      : '';
    const ipCell = privBlock
      ? `<div style="display:flex;flex-direction:column;gap:.1rem;min-width:150px">${tsIp}<div>${privBlock}</div></div>`
      : `<div style="min-width:150px">${tsIp}</div>`;

    return `<div style="display:flex;align-items:center;gap:.65rem;padding:.3rem .55rem;border-radius:6px;background:rgba(0,212,255,0.04);border:1px solid rgba(0,212,255,0.09)">
      <span style="width:7px;height:7px;border-radius:50%;background:${col};display:inline-block;flex-shrink:0${glow}"></span>
      <span style="font-size:.8rem;font-weight:600;min-width:145px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${dev.hostname}</span>
      ${ipCell}
      <span style="font-size:.84rem">${osIcon(dev.os)}</span>
      <span style="font-family:'Courier New',monospace;font-size:.65rem;color:var(--muted);flex:1">${dev.version}${upd}</span>
      ${age}
    </div>`;
  }).join('');

  setBody('tailscale', `<div style="display:flex;flex-direction:column;gap:.3rem">${rows}</div>`);
}

function fmtAge(s) {
  if (s < 60)   return s + 's ago';
  if (s < 3600) return Math.floor(s / 60) + 'm ago';
  return Math.floor(s / 3600) + 'h ago';
}

function renderSpeedtest(d) {
  const ageEl = document.getElementById('speedtest-age');
  if (!d.online) {
    setBadge('speedtest', false, d.untested ? 'Not tested' : 'Error');
    setBody('speedtest', `<span style="color:var(--muted);font-size:.85rem">${d.error ?? 'No results yet — click Run Test'}</span>`);
    if (ageEl) ageEl.textContent = '—';
    return;
  }
  setBadge('speedtest', true, 'OK');
  if (ageEl) ageEl.textContent = d.age_s != null ? 'cached · ' + fmtAge(d.age_s) : '';

  const dlBar  = Math.min(100, Math.round(d.download / 1000 * 100));
  const ulBar  = Math.min(100, Math.round(d.upload  / 1000 * 100));

  const serverLine = d.result_url
    ? `<a href="${d.result_url}" target="_blank" style="color:var(--cyan);font-family:'Courier New',monospace;font-size:.72rem;text-decoration:none">${d.server || '—'} ↗</a>`
    : `<span style="font-size:.72rem;font-family:'Courier New',monospace;color:var(--muted)">${d.server || '—'}</span>`;

  const jitterHtml = d.jitter != null ? statBox('Jitter', d.jitter + ' ms') : '';

  setBody('speedtest', `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem 1rem;margin-bottom:.4rem">
      <div>
        <div class="stat-label">Download</div>
        <div style="font-size:1.6rem;font-weight:800;font-family:'Courier New',monospace;color:#00ffcc;text-shadow:0 0 22px rgba(0,255,204,0.7);line-height:1.1">${d.download}<span style="font-size:.75rem;font-weight:400;color:var(--muted);margin-left:.25rem">Mbps</span></div>
        <div class="bar" style="margin-top:.35rem"><div class="bar-fill green" style="width:${dlBar}%"></div></div>
      </div>
      <div>
        <div class="stat-label">Upload</div>
        <div style="font-size:1.6rem;font-weight:800;font-family:'Courier New',monospace;color:#38c8ff;text-shadow:0 0 22px rgba(56,200,255,0.7);line-height:1.1">${d.upload}<span style="font-size:.75rem;font-weight:400;color:var(--muted);margin-left:.25rem">Mbps</span></div>
        <div class="bar" style="margin-top:.35rem"><div class="bar-fill green" style="width:${ulBar}%"></div></div>
      </div>
    </div>
    <div class="stats">
      ${statBox('Ping', d.ping + ' ms')}
      ${jitterHtml}
      ${statBox('ISP', d.isp, true)}
    </div>
    <div style="margin-top:.4rem;display:flex;align-items:center;gap:.4rem">
      <span style="font-size:.62rem;color:var(--muted);font-family:'Courier New',monospace;text-transform:uppercase;letter-spacing:.07em">Server</span>
      ${serverLine}
    </div>
  `);
}

let speedtestRunning = false;
function runSpeedtest() {
  if (speedtestRunning) return;
  speedtestRunning = true;
  const btn = document.getElementById('speedtest-run-btn');
  if (btn) { btn.textContent = '⏳ Testing…'; btn.style.pointerEvents = 'none'; }
  setBadge('speedtest', false, 'Testing…');
  document.getElementById('body-speedtest').innerHTML = '<div class="skeleton" style="width:90%"></div><div class="skeleton" style="width:70%;margin-top:.4rem"></div>';

  fetch('portal.php?action=run_speedtest')
    .then(r => r.json())
    .then(d => { renderSpeedtest(d); })
    .catch(() => { setBadge('speedtest', false, 'Error'); setBody('speedtest', '<span style="color:var(--muted)">Test failed</span>'); })
    .finally(() => {
      speedtestRunning = false;
      if (btn) { btn.textContent = '▶ Run Test'; btn.style.pointerEvents = ''; }
    });
}

const renderers = {
  mikrotik:      renderMikrotik,
  pihole:        (d) => renderPihole(d, 'pihole'),
  pihole2:       (d) => renderPihole(d, 'pihole2'),
  synology:      renderSynology,
  router:        renderRouter,
  cameras:       renderCameras,
  tesla_charger: renderTesla_charger,
  deye:          renderDeye,
  tailscale:     renderTailscale,
  speedtest:     renderSpeedtest,
};

// ── Camera 157 snapshot refresh ───────────────────────────────────────────────
function loadCam157() {
  const img     = document.getElementById('cam157-img');
  const overlay = document.getElementById('cam157-overlay');
  const badge   = document.getElementById('badge-cam157');
  if (!img) return;

  const probe = new Image();
  probe.onload = () => {
    img.style.opacity = '0';
    img.src = probe.src;
    img.onload = () => { img.style.opacity = '1'; };
    if (overlay) overlay.style.display = 'none';
    badge.className = 'badge badge-online';
    badge.textContent = 'Live';
    const now = new Date().toLocaleTimeString('en-GB', {hour:'2-digit', minute:'2-digit', second:'2-digit'});
    document.getElementById('cam157-ts').textContent = now;
  };
  probe.onerror = () => {
    if (overlay) { overlay.style.display = 'flex'; overlay.textContent = 'Offline'; }
    badge.className = 'badge badge-offline';
    badge.textContent = 'Offline';
  };
  probe.src = `portal.php?snapshot=157&t=${Date.now()}`;
}

function triggerCam157() { loadCam157(); }

loadCam157();
setInterval(loadCam157, 5000);

async function refreshAll() {
  document.getElementById('last-refresh').textContent = 'refreshing…';
  checks.forEach(c => {
    document.getElementById('badge-' + c).className = 'badge badge-loading';
    document.getElementById('badge-' + c).textContent = 'Checking';
    document.getElementById('body-' + c).innerHTML = '<div class="skeleton" style="width:80%"></div>';
  });

  await Promise.all(checks.map(c =>
    fetchCheck(c).then(d => renderers[c](d))
  ));

  const now = new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  document.getElementById('last-refresh').textContent = `last updated ${now}`;
}

// Auto-refresh every 60 s
refreshAll();
setInterval(refreshAll, 60000);

// ── Free-form card layout (drag anywhere + resize) ────────────────────────────
(function () {
  const STORE_KEY = 'jgspace-free-layout-v1';
  const grid = document.getElementById('grid');

  function getSaved() {
    try { return JSON.parse(localStorage.getItem(STORE_KEY)) || {}; } catch { return {}; }
  }

  function saveLayout() {
    const data = {};
    grid.querySelectorAll('.card').forEach(c => {
      if (c.id) data[c.id] = { x: c.offsetLeft, y: c.offsetTop, w: c.offsetWidth, h: c.offsetHeight };
    });
    localStorage.setItem(STORE_KEY, JSON.stringify(data));
  }

  function updateContainerHeight() {
    let maxY = 400;
    grid.querySelectorAll('.card').forEach(c => {
      const b = c.offsetTop + c.offsetHeight;
      if (b > maxY) maxY = b;
    });
    grid.style.minHeight = (maxY + 80) + 'px';
  }

  function initLayout() {
    const cards  = Array.from(grid.querySelectorAll('.card'));
    const saved  = getSaved();
    const rects  = cards.map(c => c.getBoundingClientRect());
    const gRect  = grid.getBoundingClientRect();

    // Hide section labels — not meaningful in free layout
    grid.querySelectorAll('.section-label').forEach(el => el.style.display = 'none');

    grid.style.display   = 'block';
    grid.style.position  = 'relative';

    cards.forEach((card, i) => {
      const s = saved[card.id];
      const r = rects[i];
      card.style.position  = 'absolute';
      card.style.margin    = '0';
      card.style.boxSizing = 'border-box';
      card.style.left      = (s ? s.x : Math.round(r.left - gRect.left)) + 'px';
      card.style.top       = (s ? s.y : Math.round(r.top  - gRect.top))  + 'px';
      card.style.width     = (s ? s.w : Math.round(r.width)) + 'px';
      if (s && s.h) card.style.minHeight = s.h + 'px';

      if (!card.querySelector('.resize-handle')) {
        const rh = document.createElement('div');
        rh.className = 'resize-handle';
        card.appendChild(rh);
      }
    });

    updateContainerHeight();
  }

  // ── Drag to move ─────────────────────────────────────────────────────────
  let dragCard = null, ox = 0, oy = 0;

  grid.addEventListener('mousedown', e => {
    const card = e.target.closest('#grid > .card');
    if (!card || e.target.closest('a, button, input, select, .resize-handle')) return;
    e.preventDefault();
    dragCard = card;
    ox = e.clientX - card.offsetLeft;
    oy = e.clientY - card.offsetTop;
    card.classList.add('is-dragging');
    card.style.zIndex     = '999';
    card.style.transition = 'none';
  });

  document.addEventListener('mousemove', e => {
    if (!dragCard) return;
    dragCard.style.left = Math.max(0, e.clientX - ox) + 'px';
    dragCard.style.top  = Math.max(0, e.clientY - oy) + 'px';
  });

  document.addEventListener('mouseup', () => {
    if (!dragCard) return;
    dragCard.classList.remove('is-dragging');
    dragCard.style.zIndex     = '';
    dragCard.style.transition = '';
    saveLayout();
    updateContainerHeight();
    dragCard = null;
  });

  // ── Resize from corner handle ─────────────────────────────────────────────
  let resCard = null, rx = 0, ry = 0, rw = 0, rh = 0;

  grid.addEventListener('mousedown', e => {
    if (!e.target.classList.contains('resize-handle')) return;
    e.stopPropagation();
    e.preventDefault();
    resCard = e.target.closest('.card');
    rx = e.clientX; ry = e.clientY;
    rw = resCard.offsetWidth; rh = resCard.offsetHeight;
    resCard.style.transition = 'none';
    resCard.style.zIndex     = '999';
  }, true);

  document.addEventListener('mousemove', e => {
    if (!resCard) return;
    const w = Math.max(220, rw + e.clientX - rx);
    const h = Math.max(100, rh + e.clientY - ry);
    resCard.style.width     = w + 'px';
    resCard.style.minHeight = h + 'px';
  });

  document.addEventListener('mouseup', () => {
    if (!resCard) return;
    resCard.style.zIndex     = '';
    resCard.style.transition = '';
    saveLayout();
    updateContainerHeight();
    resCard = null;
  });

  // Run after two frames so the grid has fully painted
  requestAnimationFrame(() => requestAnimationFrame(initLayout));
})();
</script>

<div class="orb orb-1"></div>
<div class="orb orb-2"></div>
<div class="orb orb-3"></div>
</body>
</html>
