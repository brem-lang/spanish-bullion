<?php
/**
 * GET api/prices.php: live prices for the ticker and the gold price on the hero photo.
 *
 * Fetched server-side (the browser can't call Yahoo Finance directly) and cached for 60 s
 * in the system temp folder, so visitors don't each trigger a request.
 * Adapted from the brasil-bullion app's src/lib/prices.functions.ts.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=60');

const SYMBOLS = [
    'GC=F'     => 'ORO (XAU/USD)',
    'BZ=F'     => 'PETRÓLEO BRENT',
    'CL=F'     => 'PETRÓLEO WTI',
    'SI=F'     => 'PLATA',
    'NG=F'     => 'GAS NATURAL',
    'EURUSD=X' => 'EUR/USD',
];
const CACHE_SECONDS = 60;

$cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'spanish-bullion-prices.json';
if (is_readable($cacheFile) && time() - filemtime($cacheFile) < CACHE_SECONDS) {
    readfile($cacheFile);
    exit;
}

$handles = [];
$multi = curl_multi_init();
foreach (SYMBOLS as $symbol => $label) {
    $ch = curl_init('https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($symbol) . '?range=1d&interval=1d');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
    ]);
    curl_multi_add_handle($multi, $ch);
    $handles[$symbol] = $ch;
}
do {
    curl_multi_exec($multi, $running);
    curl_multi_select($multi);
} while ($running > 0);

$quotes = [];
foreach ($handles as $symbol => $ch) {
    $json = json_decode((string) curl_multi_getcontent($ch), true);
    curl_multi_remove_handle($multi, $ch);
    $meta = $json['chart']['result'][0]['meta'] ?? null;
    $price = $meta['regularMarketPrice'] ?? null;
    if (!is_numeric($price) || $price == 0) {
        continue;
    }
    $prev = $meta['chartPreviousClose'] ?? $meta['previousClose'] ?? $price;
    $quotes[] = [
        'symbol'    => $symbol,
        'label'     => SYMBOLS[$symbol],
        'price'     => (float) $price,
        'change'    => (float) ($price - $prev),
        'changePct' => $prev ? (float) (($price - $prev) / $prev * 100) : 0.0,
    ];
}
curl_multi_close($multi);

$body = json_encode($quotes, JSON_UNESCAPED_UNICODE);
if ($quotes) {
    @file_put_contents($cacheFile, $body, LOCK_EX);
} elseif (is_readable($cacheFile)) {
    readfile($cacheFile); // Yahoo failed: serve the last good prices
    exit;
}
echo $body;
