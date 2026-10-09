<?php
// Proxy voor de vliegtuig-app: haalt data op van een paar vaste bronnen,
// met een korte cache zodat we minder vaak tegen limieten aanlopen
// en bij een hapering de vorige data teruggeven.
$allowed = [
    'api.adsb.lol'               => 1,      // cache in seconden
    'api.airplanes.live'         => 1,
    'api.adsbdb.com'             => 86400,  // routes veranderen niet
    'commons.wikimedia.org'      => 86400,
    'query.wikidata.org'         => 86400,  // leiders, democratie-index, landenfeiten
    'nominatim.openstreetmap.org'=> 86400,  // plaatsnaam bij je GPS-locatie
    'raw.githubusercontent.com'  => 86400,  // Big Mac-index (CSV van The Economist)
    'api.frankfurter.dev'        => 3600,   // wisselkoersen (dagelijks bijgewerkt)
    'api.worldbank.org'          => 86400,  // inwoners, bbp, levensverwachting
    'rss.marketingtools.apple.com' => 3600, // hitlijsten per land (Apple Music)
    'de1.api.radio-browser.info' => 86400,  // radiozenders per land
    'at1.api.radio-browser.info' => 86400,
    'nl1.api.radio-browser.info' => 86400,
    'overpass-api.de'            => 86400,  // plaatsnamen op de radar (OpenStreetMap)
];

// ---------------------------------------------------------------------------
// Radiotekst: welk nummer speelt er nu? (?icy=<stationuuid van radio-browser>)
// Veel Icecast/Shoutcast-streams sturen de titel mee in de stream zelf. Een
// browser kan die niet lezen, deze proxy wel: hij leest het begin van de stream
// tot het eerste titelblok en stopt dan. Alleen zenders die in radio-browser.info
// staan worden benaderd (de app stuurt een zender-ID, geen URL).
// ---------------------------------------------------------------------------
function icy_title($url) {
    $metaint = 0; $buf = ''; $title = null; $raw = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Icy-MetaData: 1'],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS=> CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_USERAGENT      => 'SchipholOverhead/1.0 (https://fodis.nl/fly)',
        CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$metaint) {
            if (preg_match('/^icy-metaint:\s*(\d+)/i', $h, $m)) $metaint = (int)$m[1];
            return strlen($h);
        },
        CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (&$metaint, &$buf, &$title, &$raw) {
            $n = strlen($data);                                          // dit moet terug om door te lezen
            if ($metaint <= 0) {
                // oude Shoutcast antwoordt met "ICY 200 OK"; curl ziet de headers dan als data
                $raw .= $data;
                if (strncmp($raw, 'ICY', 3) !== 0 || strlen($raw) > 16384) return -1;   // stream stuurt geen titels
                $end = strpos($raw, "\r\n\r\n");
                if ($end === false) return $n;
                if (!preg_match('/^icy-metaint:\s*(\d+)/im', substr($raw, 0, $end), $m)) return -1;
                $metaint = (int)$m[1];
                $data = substr($raw, $end + 4);
            }
            if ($metaint > 262144) return -1;
            $buf .= $data;
            if (strlen($buf) <= $metaint) return $n;
            $len = ord($buf[$metaint]) * 16;
            if ($len === 0) { $title = ''; return -1; }                 // leeg titelblok
            if (strlen($buf) < $metaint + 1 + $len) return $n;
            $meta = substr($buf, $metaint + 1, $len);
            $title = preg_match("/StreamTitle='(.*?)';/s", $meta, $m) ? $m[1] : '';
            return -1;                                                   // genoeg gelezen: stoppen
        },
    ]);
    if (defined('CURLOPT_HTTP09_ALLOWED')) curl_setopt($ch, CURLOPT_HTTP09_ALLOWED, true);   // oude Shoutcast ("ICY 200 OK")
    curl_exec($ch);
    curl_close($ch);
    if ($title === null) return null;
    $title = trim($title);
    if ($title !== '' && !mb_check_encoding($title, 'UTF-8')) $title = mb_convert_encoding($title, 'UTF-8', 'ISO-8859-1');
    return $title;
}

function public_host($url) {
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return false;
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) return false;
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    }
    return true;
}

if (isset($_GET['icy']) && !defined('ICY_TEST')) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $uuid = strtolower($_GET['icy']);
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
        http_response_code(400); echo json_encode(['error' => 'ongeldige zender']); exit;
    }
    $cacheFile = sys_get_temp_dir() . '/flyicy_' . $uuid . '.json';
    if (is_file($cacheFile) && time() - filemtime($cacheFile) < 15) { readfile($cacheFile); exit; }

    // stream-URL opzoeken bij radio-browser (1 dag bewaren)
    $stFile = sys_get_temp_dir() . '/flyicy_st_' . $uuid . '.json';
    $station = is_file($stFile) && time() - filemtime($stFile) < 86400 ? json_decode(file_get_contents($stFile), true) : null;
    if (!$station) {
        foreach (['de1', 'at1', 'nl1'] as $h) {
            $ch = curl_init("https://$h.api.radio-browser.info/json/stations/byuuid/$uuid");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_USERAGENT => 'SchipholOverhead/1.0 (https://fodis.nl/fly)']);
            $arr = json_decode((string)curl_exec($ch), true);
            curl_close($ch);
            if (!empty($arr[0]['url_resolved'])) { $station = ['url' => $arr[0]['url_resolved'], 'name' => $arr[0]['name'] ?? '']; break; }
        }
        if ($station) @file_put_contents($stFile, json_encode($station));
    }
    $out = ['title' => null];
    if ($station && preg_match('~^https?://~i', $station['url']) && public_host($station['url'])) {
        $t = icy_title($station['url']);
        if ($t !== null && $t !== '' && strcasecmp($t, trim($station['name'])) !== 0) {
            $out['title'] = $t;
            $parts = preg_split('/\s+[-\x{2013}\x{2014}]\s+/u', $t, 2);
            if (count($parts) === 2) { $out['artist'] = trim($parts[0]); $out['song'] = trim($parts[1]); }
        }
    }
    $json = json_encode($out, JSON_UNESCAPED_UNICODE);
    @file_put_contents($cacheFile, $json, LOCK_EX);
    echo $json;
    exit;
}

header('Cache-Control: no-store');

$url = $_GET['url'] ?? '';
$p = parse_url($url);
$host = $p['host'] ?? '';
if (!$p || ($p['scheme'] ?? '') !== 'https' || !isset($allowed[$host])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'URL niet toegestaan']);
    exit;
}
// GitHub alleen voor de Big Mac-data
if ($host === 'raw.githubusercontent.com' && strpos($p['path'] ?? '', '/TheEconomist/big-mac-data/') !== 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'URL niet toegestaan']);
    exit;
}

$isCsv = ($host === 'raw.githubusercontent.com');
header($isCsv ? 'Content-Type: text/csv; charset=utf-8' : 'Content-Type: application/json; charset=utf-8');

$cacheFile = sys_get_temp_dir() . '/flyproxy2_' . md5($url) . '.cache';
$age = is_file($cacheFile) ? time() - filemtime($cacheFile) : PHP_INT_MAX;

// Verse cache? Direct teruggeven.
if ($age < $allowed[$host]) {
    readfile($cacheFile);
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 2,
    CURLOPT_USERAGENT      => 'SchipholOverhead/1.0 (https://fodis.nl/fly)', // o.a. adsb.lol en Nominatim eisen een User-Agent
    CURLOPT_HTTPHEADER     => [$isCsv ? 'Accept: text/csv,text/plain' : 'Accept: application/json'],
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

$valid = $isCsv
    ? (strlen($body) > 200 && stripos($body, '<html') === false)
    : (json_decode($body) !== null);
$ok = $body !== false && $code >= 200 && $code < 300 && $valid;

if ($ok) {
    @file_put_contents($cacheFile, $body, LOCK_EX);
    echo $body;
    exit;
}

// Mislukt: geef recente oude data terug als die er is (max 60 s oud voor vluchtdata).
$staleLimit = $allowed[$host] > 60 ? PHP_INT_MAX : 60;
if ($age < $staleLimit) {
    header('X-Proxy-Stale: 1');
    readfile($cacheFile);
    exit;
}

http_response_code(502);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['error' => $err ?: "Bron gaf HTTP $code"]);
