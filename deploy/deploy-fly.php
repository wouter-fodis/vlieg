<?php
// Haalt de nieuwste versie van de vlieg-app van GitHub en zet die in public_html/fly.
// Draait elke minuut via een cronjob. Zet dit bestand NAAST public_html (dus niet erin):
//   domains/fodis.nl/deploy-fly.php
//
// Snel en zuinig: eerst één klein verzoek om te zien wat de nieuwste commit is.
// Alleen als die veranderd is, worden de bestanden van precies die commit opgehaald
// (die URL's worden niet door GitHub gecachet, dus je krijgt meteen de nieuwe versie).

$REPO   = 'wouter-fodis/vlieg';
$BRANCH = 'main';
$TARGET = __DIR__ . '/public_html/fly';
$STATE  = __DIR__ . '/.deploy-fly-commit';
$FILES  = [
    // bestand in de repo => controle dat het geen foutpagina is
    'index.html' => function ($b) { return stripos($b, '</html>') !== false; },
    'proxy.php'  => function ($b) { return strpos(ltrim($b), '<?php') === 0; },
];

function fetch($url, $ua = 'fodis-fly-deploy') {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => $ua,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

// 1. Nieuwste commit opvragen (via git zelf, geen API-limiet)
[$code, $refs] = fetch("https://github.com/$REPO.git/info/refs?service=git-upload-pack", 'git/2.40');
if ($code !== 200 || !preg_match('~([0-9a-f]{40}) refs/heads/' . preg_quote($BRANCH, '~') . '\b~', $refs, $m)) {
    echo date('c') . " kon nieuwste commit niet ophalen (HTTP $code)\n";
    exit;
}
$sha = $m[1];
if (is_file($STATE) && trim(file_get_contents($STATE)) === $sha) exit;   // niets nieuws

// 2. Bestanden van precies die commit ophalen
if (!is_dir($TARGET)) mkdir($TARGET, 0755, true);
$allOk = true;
foreach ($FILES as $name => $looksValid) {
    [$code, $body] = fetch("https://raw.githubusercontent.com/$REPO/$sha/$name");
    if ($body === false || $code !== 200 || strlen($body) < 50 || !$looksValid($body)) {
        echo date('c') . " $name: overgeslagen (HTTP $code)\n";
        $allOk = false;
        continue;
    }
    $dest = "$TARGET/$name";
    if (is_file($dest) && hash_file('sha256', $dest) === hash('sha256', $body)) continue;
    $tmp = "$dest.tmp";
    file_put_contents($tmp, $body, LOCK_EX);
    rename($tmp, $dest);                       // in één keer vervangen, nooit een half bestand
    echo date('c') . " $name: bijgewerkt naar " . substr($sha, 0, 7) . "\n";
}
if ($allOk) file_put_contents($STATE, $sha);   // pas onthouden als alles gelukt is
