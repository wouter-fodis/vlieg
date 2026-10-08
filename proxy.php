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
];

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
