<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json');

/* ─────────────────────────────────────────
   Sécurité + contexte entreprise
───────────────────────────────────────── */
if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? null) !== 'administrateur') {
    echo json_encode(['success' => false, 'message' => 'Non autorisé']); exit;
}
$user = $_SESSION['utilisateurs'];
$ENT_ID = isset($user['entreprise_id']) ? (int)$user['entreprise_id'] : null;
if ($ENT_ID === null) {
    echo json_encode(['success' => false, 'message' => 'Contexte entreprise manquant']); exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID manquant ou invalide']); exit;
}

/* Helpers chemin */
function abs_from_rel(?string $rel): ?string {
    if (!$rel) return null;
    return rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . ltrim($rel, '/');
}
function rrmdir_if_empty($dir) {
    // essaie de supprimer le dossier s'il est vide (sécurité)
    if (is_dir($dir)) @rmdir($dir);
}
function rrmdir_recursive($dir) {
    // suppression récursive contrôlée, utilisée uniquement sous /uploads/
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $it;
        if (is_dir($path)) rrmdir_recursive($path);
        else @unlink($path);
    }
    @rmdir($dir);
}

try {
    $pdo->beginTransaction();

    /* ─────────────────────────────────────────
       1) Verrouille l’article et vérifie l’entreprise
    ────────────────────────────────────────── */
    $stmt = $pdo->prepare("
        SELECT s.photo
        FROM stock s
        WHERE s.id = :id AND s.entreprise_id = :eid
        FOR UPDATE
    ");
    $stmt->execute([':id'=>$id, ':eid'=>$ENT_ID]);
    $photoRel = $stmt->fetchColumn();
    if ($photoRel === false) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => "Article introuvable dans cette entreprise."]);
        exit;
    }

    /* ─────────────────────────────────────────
       2) Récupérer les documents à supprimer (pour effacer les fichiers après commit)
    ────────────────────────────────────────── */
    $stmt = $pdo->prepare("
        SELECT id, chemin_fichier
        FROM stock_documents
        WHERE stock_id = :sid
        ORDER BY id
    ");
    $stmt->execute([':sid'=>$id]);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ─────────────────────────────────────────
       3) Suppression des références (enfants -> parent)
    ────────────────────────────────────────── */
    // Dépôts / Chantiers
    $pdo->prepare("DELETE FROM stock_depots WHERE stock_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM stock_chantiers WHERE stock_id = ?")->execute([$id]);

    // Transferts en attente liés à cet article (peu importe la source/destination)
    $pdo->prepare("DELETE FROM transferts_en_attente WHERE article_id = ?")->execute([$id]);

    // Documents BDD
    $pdo->prepare("DELETE FROM stock_documents WHERE stock_id = ?")->execute([$id]);

    // Historique: on conserve par défaut. Si tu veux purger:
    // $pdo->prepare("DELETE FROM stock_mouvements WHERE stock_id = ?")->execute([$id]);

    // Article
    $pdo->prepare("DELETE FROM stock WHERE id = ?")->execute([$id]);

    $pdo->commit();

    /* ─────────────────────────────────────────
       4) Nettoyage fichiers (HORS transaction)
          - photo isolée si stockée en BDD
          - dossiers standards /uploads/photos/articles/{id}/ & /uploads/documents/articles/{id}/
    ────────────────────────────────────────── */

    // a) Photo stockée en base
    if (!empty($photoRel)) {
        $rel = ltrim($photoRel, '/'); // ex: uploads/photos/articles/123/abc.webp
        if (str_starts_with($rel, 'uploads/')) {
            $abs = abs_from_rel($rel);
            if ($abs && is_file($abs)) @unlink($abs);
            // tente de nettoyer le dossier parent s'il devient vide (optionnel)
            $parent = dirname($abs ?? '');
            rrmdir_if_empty($parent);
        }
    }

    // b) Dossiers "conventionnels" (sécurisés sous /uploads/)
    $photosDirAbs    = abs_from_rel("uploads/photos/articles/{$id}/");
    $documentsDirAbs = abs_from_rel("uploads/documents/articles/{$id}/");

    if ($photosDirAbs && str_contains($photosDirAbs, '/uploads/')) {
        rrmdir_recursive($photosDirAbs);
    }
    if ($documentsDirAbs && str_contains($documentsDirAbs, '/uploads/')) {
        rrmdir_recursive($documentsDirAbs);
    }

    // c) Fichiers documents individuels (au cas où ils ne soient pas dans les dossiers conventionnels)
    foreach ($docs as $d) {
        $rel = ltrim((string)($d['chemin_fichier'] ?? ''), '/');
        if ($rel && str_starts_with($rel, 'uploads/')) {
            $abs = abs_from_rel($rel);
            if ($abs && is_file($abs)) @unlink($abs);
        }
    }

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
