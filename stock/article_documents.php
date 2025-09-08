<?php
declare(strict_types=1);
// Fichier : /stock/get_documents.php

require_once __DIR__ . '/../config/init.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['utilisateurs'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Non authentifié']);
    exit;
}

$user    = $_SESSION['utilisateurs'];
$ENT_ID  = isset($user['entreprise_id']) ? (int)$user['entreprise_id'] : null;
if ($ENT_ID === null) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Contexte entreprise manquant']);
    exit;
}

$stockId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($stockId <= 0) {
    echo json_encode([]); // id manquant/invalid -> liste vide
    exit;
}

/* Helpers chemins */
$toWebUrl = function (?string $stored): ?string {
    if (!$stored) return null;
    if (strpos($stored, 'uploads/') === 0 || strpos($stored, '/uploads/') === 0) {
        return '/' . ltrim($stored, '/');
    }
    // Legacy par prudence
    return '/uploads/documents/' . ltrim($stored, '/');
};
$toAbsPath = function (?string $stored): ?string {
    if (!$stored) return null;
    if (strpos($stored, 'uploads/') === 0 || strpos($stored, '/uploads/') === 0) {
        return __DIR__ . '/../' . ltrim($stored, '/'); // on remonte depuis /stock/
    }
    // Legacy
    return __DIR__ . '/../uploads/documents/' . ltrim($stored, '/');
};

try {
    // 1) Vérifier que l’article appartient bien à l’entreprise de l’utilisateur
    $stmt = $pdo->prepare("
        SELECT 1
        FROM stock s
        WHERE s.id = :sid
          AND s.entreprise_id = :eid
        LIMIT 1
    ");
    $stmt->execute([':sid' => $stockId, ':eid' => $ENT_ID]);
    if (!$stmt->fetchColumn()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Accès refusé']);
        exit;
    }

    // 2) Récupérer les documents liés (la table est liée par stock_id)
    $stmt = $pdo->prepare("
        SELECT id, nom_affichage, chemin_fichier, taille, created_at
        FROM stock_documents
        WHERE stock_id = :sid
        ORDER BY id DESC
    ");
    $stmt->execute([':sid' => $stockId]);

    $out = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $url  = $toWebUrl($r['chemin_fichier'] ?? null);
        $size = isset($r['taille']) ? (int)$r['taille'] : null;

        // Si la taille n'est pas stockée, on tente de lire le fichier (optionnel)
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
