<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/init.php';

header('Content-Type: text/plain; charset=utf-8');

// --- Helpers pour masquer la clé et afficher joliment ---
function maskKey(?string $k): string {
    if (!$k) return '(vide)';
    $len = strlen($k);
    if ($len <= 8) return str_repeat('*', $len);
    return substr($k, 0, 4) . str_repeat('*', $len - 8) . substr($k, -4);
}
function dumpVal($label, $val) { echo $label . ': ' . $val . "\n"; }

// 1) Est-ce que la constante est définie ? quelle longueur ?
$constDefined = defined('GOOGLE_MAPS_API_KEY');
$key = $constDefined ? GOOGLE_MAPS_API_KEY : '';
dumpVal('Constante définie', $constDefined ? 'oui' : 'non');
dumpVal('Longueur clé', (string)strlen($key));
dumpVal('Clé masquée', maskKey($key));

// 2) Vérifier aussi la variable d’env (si tu la charges dans $_ENV via parse_ini_file)
$envKey = $_ENV['GOOGLE_MAPS_API_KEY'] ?? getenv('GOOGLE_MAPS_API_KEY') ?: '';
dumpVal('$_ENV[GOOGLE_MAPS_API_KEY] len', (string)strlen($envKey));
dumpVal('$_ENV[GOOGLE_MAPS_API_KEY] masked', maskKey($envKey));

// 3) Tenter un appel Geocoding (attention: cela consomme 1 requête)
if ($key === '') {
    echo "\n=> Clé vide: le Geocoding ne sera pas appelé.\n";
    exit;
}

$address = 'place royale, 64000 Pau, France';
$url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($address) . '&key=' . $key;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
]);
$raw = curl_exec($ch);
$err = curl_error($ch);
$info = curl_getinfo($ch);
curl_close($ch);

echo "\n--- cURL ---\n";
dumpVal('HTTP_CODE', (string)($info['http_code'] ?? 0));
dumpVal('cURL error', $err ?: '(aucune)');

if ($raw === false) {
    echo "Réponse brute: (false)\n";
    exit;
}

$data = json_decode($raw, true);
$status = $data['status'] ?? '(inconnu)';
echo "status: {$status}\n";
if (isset($data['error_message'])) {
    echo "error_message: " . $data['error_message'] . "\n";
}

// Affiche la lat/lng si OK
if ($status === 'OK') {
    $loc = $data['results'][0]['geometry']['location'] ?? null;
    if ($loc) {
        echo "lat=" . $loc['lat'] . " ; lng=" . $loc['lng'] . "\n";
    }
}
