<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../vendor/autoload.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

if (!isset($_SESSION['utilisateurs']) || (($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur')) {
  http_response_code(403); exit('Forbidden');
}

$entrepriseId = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);
$stockId      = (int)($_POST['id'] ?? 0);
$regenerate   = !empty($_POST['regenerate']);

$st = $pdo->prepare("SELECT id, entreprise_id, qr_token FROM stock WHERE id=:id LIMIT 1");
$st->execute([':id'=>$stockId]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row || ($entrepriseId && (int)$row['entreprise_id'] !== $entrepriseId)) {
  header('Location: /stock/article.php?id='.$stockId.'&err=notfound'); exit;
}

if ($regenerate || empty($row['qr_token'])) {
  $newToken = bin2hex(random_bytes(16)); // 32 chars
  $up = $pdo->prepare("UPDATE stock SET qr_token=:t, has_qrcode=1 WHERE id=:id");
  $up->execute([':t'=>$newToken, ':id'=>$stockId]);
  $token = $newToken;
} else {
  $token = $row['qr_token'];
}

// URL encodée
$baseUrl = $_ENV['APP_BASE_URL'] ?? (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$url = rtrim($baseUrl, '/') . '/stock/article.php?t=' . rawurlencode($token);

// ====== ICI: CHOIX DU BON DOSSIER ======
$entDirId = (int)($row['entreprise_id'] ?: $entrepriseId); // fallback si NULL/0 en base
$dir = __DIR__ . '/qrcodes/' . $entDirId;
if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

$filePath = $dir . "/stock_{$stockId}.png";

// Génération
$options = new QROptions([
  'outputType'   => QRCode::OUTPUT_IMAGE_PNG,
  'eccLevel'     => QRCode::ECC_L,
  'scale'        => 6,
  'imageBase64'  => false,   // <- clé du problème (selon versions: 'outputBase64' => false)
]);
$png = (new QRCode($options))->render($url);


// Écriture
if (file_put_contents($filePath, $pngData) === false) {
  error_log('QR: échec d’écriture '.$filePath);
}
error_log('QR généré: '.$filePath.' ('.(is_file($filePath)?filesize($filePath):0).' octets)');

header('Location: /stock/article.php?id='.$stockId.'&qr=ok');
