<?php
// Fichier : /stock/get_documents.php (par ex.)
require_once __DIR__ . '/../config/init.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['utilisateurs'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Non authentifié']);
    exit;
}

$stockId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($stockId <= 0) {
    echo json_encode([]); // id manquant/invalid -> liste vide
    exit;
}

// Helper: construit une URL web à partir du chemin stocké
$toWebUrl = function (?string $stored): ?string {
    if (!$stored) return null;
    // Si le champ commence déjà par "uploads/" ou "/uploads/", on préfixe juste par "/"
    if (strpos($stored, 'uploads/') === 0 || strpos($stored, '/uploads/') === 0) {
        return '/' . ltrim($stored, '/');
    }
    // Legacy (par prudence) : chemin sans dossier -> on suppose "uploads/documents/"
    return '/uploads/documents/' . ltrim($stored, '/');
};

// Helper: chemin absolu disque pour retrouver la taille si absente
$toAbsPath = function (?string $stored): ?string {
    if (!$stored) return null;
    if (strpos($stored, 'uploads/') === 0 || strpos($stored, '/uploads/') === 0) {
        // On remonte d'un niveau car ce script est dans /stock/
        return __DIR__ . '/../' . ltrim($stored, '/');
    }
    // Legacy
    return __DIR__ . '/../uploads/documents/' . ltrim($stored, '/');
};

try {
    $stmt = $pdo->prepare("
        SELECT id, nom_affichage, chemin_fichier, taille, created_at
        FROM stock_documents
        WHERE stock_id = ?
        ORDER BY id DESC
    ");
    $stmt->execute([$stockId]);

    $out = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $url  = $toWebUrl($r['chemin_fichier'] ?? null);
        $size = isset($r['taille']) ? (int)$r['taille'] : null;

        // Si la taille n'est pas stockée, on tente de la déduire depuis le fichier (optionnel)
        if ($size === null) {
            $abs = $toAbsPath($r['chemin_fichier'] ?? null);
            if ($abs && is_file($abs)) {
                $size = (int) @filesize($abs);
            }
        }

        $out[] = [
            'id'         => (int)($r['id'] ?? 0),
            'nom'        => $r['nom_affichage'] ?? null,
            'url'        => $url,
            'size'       => $size,
            'created_at' => $r['created_at'] ?? null,
        ];
    }

    echo json_encode($out);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([]);
}
