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

// (Re)génère un token si besoin
if ($regenerate || empty($row['qr_token'])) {
  $token = bin2hex(random_bytes(16)); // 32 chars
  $pdo->prepare("UPDATE stock SET qr_token=:t, has_qrcode=1 WHERE id=:id")
      ->execute([':t'=>$token, ':id'=>$stockId]);
} else {
  $token = $row['qr_token'];
}

// Base URL
$baseUrl = $_ENV['APP_BASE_URL']
  ?? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];

// URL encodée dans le QR → ouvre directement l’onglet État
$url = rtrim($baseUrl, '/') . '/stock/article.php?t=' . rawurlencode($token) . '&tab=etat';

// Dossier public de sortie (par entreprise) — basé sur la webroot
$entDirId = (int)($row['entreprise_id'] ?: $entrepriseId);
$webDir   = '/stock/qrcodes/' . $entDirId; // URL publique
$absDir   = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $webDir; // chemin disque correspondant

if (!is_dir($absDir)) {
  @mkdir($absDir, 0775, true);
}

$fileName    = "stock_{$stockId}.png";
$filePathAbs = $absDir . '/' . $fileName;                // disque
$filePathRel = $webDir . '/' . $fileName;                // URL à stocker en BDD

// Génération du PNG (désactivation explicite du Base64)
$options = new QROptions([
  'outputType'  => QRCode::OUTPUT_IMAGE_PNG,
  'eccLevel'    => QRCode::ECC_L,
  'scale'       => 6,
  'imageBase64' => false,   // <-- clé cruciale selon les versions
]);

$pngData = (new QRCode($options))->render($url);

// Si c’est encore du base64 (certaines versions ignorent imageBase64)
if (str_starts_with($pngData, 'data:image')) {
  $pngData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $pngData));
}

file_put_contents($filePathAbs, $pngData);

// Retour à la fiche (+ petite astuce pour forcer le refresh du PNG en cas de cache)
header('Location: /stock/article.php?id='.$stockId.'&qr=ok#qr='.time());
