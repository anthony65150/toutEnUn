<?php

declare(strict_types=1);

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

function app_base_url(): string
{
  // Utilise APP_URL si défini (ex: http://localhost:3000), sinon auto
  $schemeHost = (($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
  return rtrim($_ENV['APP_URL'] ?? $schemeHost, '/');
}

function uuidv4(): string
{
  $d = random_bytes(16);
  $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
  $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

/**
 * Génère le PNG du QR et renvoie le CHEMIN ABSOLU du fichier créé.
 *
 * Écrit physiquement sous: {DOCUMENT_ROOT}/stock/qrcodes/{entrepriseId}/stock_{stockId}.png
 * L'URL WEB correspondante est   : /stock/qrcodes/{entrepriseId}/stock_{stockId}.png
 * (à enregistrer dans stock.qr_image_path de ton côté)
 */
function generateStockQr(string $baseUrl, int $entrepriseId, int $stockId, string $qrToken): string {
  // URL encodée dans le QR → ouvre l’onglet État
  $url = rtrim($baseUrl, '/') . '/stock/article.php?t=' . rawurlencode($qrToken) . '&tab=etat';

  // Dossiers web/disque alignés
  $webDir = '/stock/qrcodes/' . $entrepriseId;
  $absDir = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $webDir;

  if (!is_dir($absDir)) {
    @mkdir($absDir, 0775, true);
  }

  $fileName = "stock_{$stockId}.png";
  $absPath  = $absDir . '/' . $fileName;

  // ✅ Correction ici : on récupère la chaîne binaire, puis on l’écrit
  $options = new QROptions([
    'outputType' => QRCode::OUTPUT_IMAGE_PNG,
    'eccLevel'   => QRCode::ECC_L,
    'scale'      => 6,
  ]);

  $pngData = (new QRCode($options))->render($url);
  file_put_contents($absPath, $pngData);  // <-- écrit réellement l’image

  return $absPath; // utile si tu veux aussi renvoyer le chemin absolu
}

