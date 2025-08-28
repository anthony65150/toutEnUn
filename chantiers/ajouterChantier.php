<?php
// Si ce fichier est dans /chantiers/
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

/* ====== Inputs ====== */
$chantierId   = isset($_POST['chantier_id']) ? (int)$_POST['chantier_id'] : 0;
$nom          = trim($_POST['nom'] ?? '');
$description  = trim($_POST['description'] ?? '');
$dateDebut    = $_POST['date_debut'] ?: null;
$dateFin      = $_POST['date_fin']   ?: null;

/* Multi-chefs depuis les modales (chefs[]) ; fallback mono-chef (responsable_id) */
$chefIds = array_filter(array_map('intval', $_POST['chefs'] ?? []));
if (!$chefIds && isset($_POST['responsable_id'])) {
    $rid = (int)$_POST['responsable_id'];
    if ($rid > 0) $chefIds = [$rid];
}

/* Validation minimale */
if ($nom === '' || empty($chefIds)) {
    $_SESSION['flash'] = "Erreur : nom et au moins un chef sont requis.";
    header("Location: chantiers_admin.php?success=error");
    exit;
}

/* On utilise le premier chef comme responsable_id pour compat table chantiers */
$responsableId = $chefIds[0];

try {
    $pdo->beginTransaction();

    if ($chantierId > 0) {
        /* ===== UPDATE ===== */
        $stmt = $pdo->prepare("
            UPDATE chantiers
               SET nom = ?, description = ?, date_debut = ?, date_fin = ?, responsable_id = ?
             WHERE id = ?
        ");
        $stmt->execute([$nom, $description, $dateDebut, $dateFin, $responsableId, $chantierId]);

        // Reset liaisons et réinsertion
        $pdo->prepare("DELETE FROM utilisateur_chantiers WHERE chantier_id = ?")->execute([$chantierId]);
        $ins = $pdo->prepare("INSERT INTO utilisateur_chantiers (utilisateur_id, chantier_id) VALUES (?, ?)");
        foreach ($chefIds as $uid) {
            $ins->execute([$uid, $chantierId]);
        }

        $pdo->commit();
        $_SESSION['flash'] = "Chantier modifié avec succès.";
        $redirectId  = $chantierId;
        $successType = "update";

    } else {
        /* ===== INSERT ===== */
        $stmt = $pdo->prepare("
            INSERT INTO chantiers (nom, description, date_debut, date_fin, responsable_id)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$nom, $description, $dateDebut, $dateFin, $responsableId]);
        $newChantierId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare("INSERT INTO utilisateur_chantiers (utilisateur_id, chantier_id) VALUES (?, ?)");
        foreach ($chefIds as $uid) {
            $ins->execute([$uid, $newChantierId]);
        }

        $pdo->commit();
        $_SESSION['flash'] = "Chantier créé avec succès.";
        $redirectId  = $newChantierId;
        $successType = "create";
    }

    header("Location: chantiers_admin.php?success={$successType}&highlight={$redirectId}");
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // error_log($e->getMessage()); // utile en dev
    $_SESSION['flash'] = "Erreur serveur lors de l'enregistrement.";
    header("Location: chantiers_admin.php?success=error");
    exit;
}
