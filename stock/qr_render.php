<?php

declare(strict_types=1);



require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../vendor/autoload.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

// 1) Sécurité: seul un admin connecté peut appeler ce script (ton besoin)
if (!isset($_SESSION['utilisateurs']) || (($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur')) {
    http_response_code(403);
    exit('Forbidden');
}

// 2) Récup token via id ou via t=
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$token = '';
if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $st = $pdo->prepare("SELECT qr_token FROM stock WHERE id=:id LIMIT 1");
    $st->execute([':id' => $id]);
    $token = (string)($st->fetchColumn() ?: '');
} elseif (isset($_GET['t'])) {
    $token = (string)$_GET['t'];
}

// Token minimum vital
if ($token === '' || strlen($token) < 16 || strlen($token) > 64) {
    http_response_code(404);
    exit('Not found');
}

// 3) URL à encoder
$baseUrl = $_ENV['APP_BASE_URL'] ?? ((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']);
$url = rtrim($baseUrl, '/') . '/stock/article.php?t=' . rawurlencode($token);

// 4) Génère le PNG en mémoire
$options = new QROptions([
  'outputType'   => QRCode::OUTPUT_IMAGE_PNG,
  'eccLevel'     => QRCode::ECC_L,
  'scale'        => 6,
  'imageBase64'  => false,   // <- clé du problème (selon versions: 'outputBase64' => false)
]);
$png = (new QRCode($options))->render($url);


// 5) Sortie binaire “safe”

// Couper toute compression/transformation
@ini_set('zlib.output_compression', '0');
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}

// Vider TOUT tampon déjà ouvert (et ne PAS en ouvrir de nouveau)
while (ob_get_level() > 0) {
    ob_end_clean();
}

// En-têtes stricts image PNG
header('Content-Type: image/png');
// NE PAS fixer Content-Length (évite les corruptions si quelque chose modifie le flux)
header('Content-Disposition: inline; filename="qr.png"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Sortie et fin immédiate
echo $png;
exit;
