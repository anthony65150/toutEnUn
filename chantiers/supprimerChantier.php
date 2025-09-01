<?php
// /chantiers/supprimerChantier.php
require_once __DIR__ . '/../config/init.php';

if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur') {
    header('Location: /connexion.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: chantiers_admin.php'); exit;
}

/* ====== CSRF ====== */
if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
    $_SESSION['flash'] = "Erreur CSRF.";
    header("Location: chantiers_admin.php?success=error_csrf"); exit;
}

$entrepriseId = (int)($_SESSION['entreprise_id'] ?? 0);
if (!$entrepriseId) {
    $_SESSION['flash'] = "Entreprise non définie.";
    header("Location: chantiers_admin.php?success=error"); exit;
}

$chantierId = (int)($_POST['delete_id'] ?? 0);
if ($chantierId <= 0) {
    $_SESSION['flash'] = "ID de chantier invalide.";
    header("Location: chantiers_admin.php?success=error"); exit;
}

/* ====== Vérifier que le chantier appartient à l'entreprise ====== */
$stChk = $pdo->prepare("SELECT id FROM chantiers WHERE id = ? AND entreprise_id = ?");
$stChk->execute([$chantierId, $entrepriseId]);
if (!$stChk->fetchColumn()) {
    $_SESSION['flash'] = "Chantier introuvable pour cette entreprise.";
    header("Location: chantiers_admin.php?success=error"); exit;
}

/* ====== Vérifs bloquantes avant suppression ====== */
/* 1) Stock présent sur ce chantier ? */
$hasStock = false;
try {
    // Si votre table stock_chantiers n'a pas entreprise_id, retirez la condition AND sc.entreprise_id = :eid
    $st = $pdo->prepare("
        SELECT SUM(COALESCE(sc.quantite,0))
        FROM stock_chantiers sc
        WHERE sc.chantier_id = :cid
          AND (:eid IS NULL OR sc.entreprise_id = :eid)
    ");
    // Le :eid IS NULL joue le rôle de “optionnel” si la colonne n'existe pas et que vous enlevez la condition ci-dessus.
    $st->execute([':cid' => $chantierId, ':eid' => $entrepriseId]);
    $hasStock = ((int)$st->fetchColumn()) > 0;
} catch (Throwable $e) {
    $hasStock = false; // si table manquante, on ignore la vérif
}

if ($hasStock) {
    $_SESSION['flash'] = "Suppression impossible : du stock est présent sur ce chantier.";
    header("Location: chantiers_admin.php?success=error"); exit;
}

/* 2) Transferts en attente impliquant ce chantier ? */
$hasPending = false;
try {
    // Si pas de colonne entreprise_id dans transferts_en_attente, retirez la ligne AND tea.entreprise_id = :eid
    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM transferts_en_attente tea
        WHERE (
                (tea.source_type = 'chantier' AND tea.source_id = :cid)
             OR (tea.destination_type = 'chantier' AND tea.destination_id = :cid)
        )
        AND (:eid IS NULL OR tea.entreprise_id = :eid)
    ");
    $st->execute([':cid' => $chantierId, ':eid' => $entrepriseId]);
    $hasPending = ((int)$st->fetchColumn()) > 0;
} catch (Throwable $e) {
    $hasPending = false;
}

if ($hasPending) {
    $_SESSION['flash'] = "Suppression impossible : des transferts en attente impliquent ce chantier.";
    header("Location: chantiers_admin.php?success=error"); exit;
}

/* ====== Suppression ====== */
try {
    $pdo->beginTransaction();

    // Liaisons utilisateurs ↔ chantier (scopées entreprise)
    // Si la table n'a pas entreprise_id, supprimez la condition AND entreprise_id = :eid
    $pdo->prepare("
        DELETE FROM utilisateur_chantiers
        WHERE chantier_id = :cid AND entreprise_id = :eid
    ")->execute([':cid' => $chantierId, ':eid' => $entrepriseId]);

    // Nettoyages annexes (à activer si vous avez ces tables, en gardant le scope entreprise si présent)
    // $pdo->prepare("DELETE FROM planning_affectations WHERE chantier_id = :cid AND entreprise_id = :eid")
    //     ->execute([':cid' => $chantierId, ':eid' => $entrepriseId]);
    // $pdo->prepare("DELETE FROM transferts_historique WHERE source_type='chantier' AND source_id = :cid AND entreprise_id = :eid")
    //     ->execute([':cid' => $chantierId, ':eid' => $entrepriseId]);

    // Supprimer le chantier (protégé par entreprise)
    $stDel = $pdo->prepare("DELETE FROM chantiers WHERE id = :cid AND entreprise_id = :eid");
    $stDel->execute([':cid' => $chantierId, ':eid' => $entrepriseId]);

    if ($stDel->rowCount() === 0) {
        throw new RuntimeException("Suppression refusée (chantier non trouvé pour l'entreprise).");
    }

    $pdo->commit();

    $_SESSION['flash'] = "Chantier supprimé avec succès.";
    header("Location: chantiers_admin.php?success=delete"); exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // error_log($e->getMessage());
    $_SESSION['flash'] = "Erreur serveur lors de la suppression du chantier.";
    header("Location: chantiers_admin.php?success=error"); exit;
}
