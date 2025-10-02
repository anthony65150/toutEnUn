<?php
declare(strict_types=1);

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

function generateStockQr(string $baseUrl, int $entrepriseId, int $stockId, string $qrToken): string {
  $url = rtrim($baseUrl, '/') . '/stock/article.php?t=' . rawurlencode($qrToken);

  $dir = __DIR__ . '/../public/qrcodes/' . $entrepriseId;
  if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

  $filePath = $dir . "/stock_{$stockId}.png";

  $options = new QROptions([
    'eccLevel' => QRCode::ECC_L,
    'scale'    => 6,
  ]);

  (new QRCode($options))->render($url, $filePath);
  return $filePath;
}
