<?php
// Fichier situé dans /chantiers/
require_once __DIR__ . '/../config/init.php';

if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur') {
    header('Location: /connexion.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: chantiers_admin.php');
    exit;
}

/* ====== CSRF ====== */
if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
    $_SESSION['flash'] = "Erreur CSRF.";
    header("Location: chantiers_admin.php?success=error_csrf");
    exit;
}

$chantierId = (int)($_POST['delete_id'] ?? 0);
if ($chantierId <= 0) {
    $_SESSION['flash'] = "ID de chantier invalide.";
    header("Location: chantiers_admin.php?success=error");
    exit;
}

/* ====== Vérifs bloquantes avant suppression ====== */
// 1) Stock présent sur ce chantier ?
$hasStock = false;
try {
    $stmt = $pdo->prepare("SELECT SUM(COALESCE(sc.quantite,0)) FROM stock_chantiers sc WHERE sc.chantier_id = ?");
    $stmt->execute([$chantierId]);
    $total = (int)$stmt->fetchColumn();
    $hasStock = $total > 0;
} catch (Throwable $e) {
    // En cas d'erreur de table manquante, on ignore la vérif (selon ton schéma)
    $hasStock = false;
}

if ($hasStock) {
    $_SESSION['flash'] = "Suppression impossible : du stock est encore présent sur ce chantier. Transfère ou mets les quantités à 0 avant de supprimer.";
    header("Location: chantiers_admin.php?success=error");
    exit;
}

// 2) Transferts en attente impliquant ce chantier ?
$hasPending = false;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM transferts_en_attente tea
        WHERE (tea.source_type = 'chantier' AND tea.source_id = ?)
           OR (tea.destination_type = 'chantier' AND tea.destination_id = ?)
    ");
    $stmt->execute([$chantierId, $chantierId]);
    $hasPending = ((int)$stmt->fetchColumn()) > 0;
} catch (Throwable $e) {
    $hasPending = false;
}

if ($hasPending) {
    $_SESSION['flash'] = "Suppression impossible : des transferts en attente impliquent ce chantier. Traite/annule ces transferts puis réessaie.";
    header("Location: chantiers_admin.php?success=error");
    exit;
}

/* ====== Suppression ====== */
try {
    $pdo->beginTransaction();

    // Supprimer liaisons utilisateurs ↔ chantier
    $stmt = $pdo->prepare("DELETE FROM utilisateur_chantiers WHERE chantier_id = ?");
    $stmt->execute([$chantierId]);

    // (Optionnel) Si tu veux nettoyer des tables annexes, fais-le ici, ex :
    // $pdo->prepare("DELETE FROM pointages WHERE chantier_id = ?")->execute([$chantierId]);
    // $pdo->prepare("DELETE FROM transferts_historique WHERE source_type='chantier' AND source_id = ?")->execute([$chantierId]);

    // Supprimer le chantier
    $stmt = $pdo->prepare("DELETE FROM chantiers WHERE id = ?");
    $stmt->execute([$chantierId]);

    $pdo->commit();

    $_SESSION['flash'] = "Chantier supprimé avec succès.";
    header("Location: chantiers_admin.php?success=delete");
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // error_log($e->getMessage());
    $_SESSION['flash'] = "Erreur serveur lors de la suppression du chantier.";
    header("Location: chantiers_admin.php?success=error");
    exit;
}
