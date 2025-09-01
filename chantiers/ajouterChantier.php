<?php
// /chantiers/ajouterChantier.php
require_once __DIR__ . '/../config/init.php';

if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur') {
    header('Location: /connexion.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: chantiers_admin.php');
    exit;
}

/* ====== Sécurité ====== */
if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
    $_SESSION['flash'] = "Erreur CSRF.";
    header("Location: chantiers_admin.php?success=error_csrf");
    exit;
}
$entrepriseId = (int)($_SESSION['entreprise_id'] ?? 0);
if (!$entrepriseId) {
    $_SESSION['flash'] = "Entreprise non définie.";
    header("Location: chantiers_admin.php?success=error");
    exit;
}

/* ====== Inputs ====== */
$chantierId  = isset($_POST['chantier_id']) ? (int)$_POST['chantier_id'] : 0;
$nom         = trim($_POST['nom'] ?? '');
$description = trim($_POST['description'] ?? '');
$dateDebut   = ($_POST['date_debut'] ?? '') !== '' ? $_POST['date_debut'] : null;
$dateFin     = ($_POST['date_fin']   ?? '') !== '' ? $_POST['date_fin']   : null;

/* Multi-chefs depuis les modales (chefs[]) ; fallback mono-chef (responsable_id) */
$chefIds = array_values(array_filter(array_map('intval', $_POST['chefs'] ?? [])));
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

/* Vérifier que tous les chefs appartiennent à la même entreprise */
$placeholders = implode(',', array_fill(0, count($chefIds), '?'));
$check = $pdo->prepare("
    SELECT COUNT(*) FROM utilisateurs
    WHERE id IN ($placeholders) AND entreprise_id = ?
");
$check->execute([...$chefIds, $entrepriseId]);
if ((int)$check->fetchColumn() !== count($chefIds)) {
    $_SESSION['flash'] = "Un ou plusieurs chefs ne font pas partie de l'entreprise.";
    header("Location: chantiers_admin.php?success=error");
    exit;
}

/* On utilise le premier chef comme responsable_id pour compat table chantiers */
$responsableId = $chefIds[0];

try {
    $pdo->beginTransaction();

    if ($chantierId > 0) {
        /* ===== UPDATE (sécurisé par entreprise) ===== */
        $stmt = $pdo->prepare("
            UPDATE chantiers
               SET nom = :nom,
                   description = :desc,
                   date_debut = :deb,
                   date_fin = :fin,
                   responsable_id = :resp
             WHERE id = :id AND entreprise_id = :eid
        ");
        $stmt->execute([
            ':nom'  => $nom,
            ':desc' => $description,
            ':deb'  => $dateDebut,
            ':fin'  => $dateFin,
            ':resp' => $responsableId,
            ':id'   => $chantierId,
            ':eid'  => $entrepriseId,
        ]);

        if ($stmt->rowCount() === 0) {
            // soit l'ID n'existe pas, soit il n'appartient pas à l'entreprise
            throw new RuntimeException("Chantier introuvable pour cette entreprise.");
        }

        /* Reset liaisons et réinsertion (scopées entreprise) */
        // Si votre table utilisateur_chantiers n'a pas encore la colonne entreprise_id,
        // enlevez les conditions/colonnes liées à entreprise_id ci-dessous.
        $pdo->prepare("
            DELETE FROM utilisateur_chantiers
            WHERE chantier_id = :cid AND entreprise_id = :eid
        ")->execute([':cid' => $chantierId, ':eid' => $entrepriseId]);

        $ins = $pdo->prepare("
            INSERT INTO utilisateur_chantiers (utilisateur_id, chantier_id, entreprise_id)
            VALUES (:uid, :cid, :eid)
        ");
        foreach ($chefIds as $uid) {
            $ins->execute([':uid' => $uid, ':cid' => $chantierId, ':eid' => $entrepriseId]);
        }

        $pdo->commit();
        $_SESSION['flash'] = "Chantier modifié avec succès.";
        $redirectId  = $chantierId;
        $successType = "update";

    } else {
        /* ===== INSERT ===== */
        $stmt = $pdo->prepare("
            INSERT INTO chantiers (nom, description, date_debut, date_fin, responsable_id, entreprise_id)
            VALUES (:nom, :desc, :deb, :fin, :resp, :eid)
        ");
        $stmt->execute([
            ':nom'  => $nom,
            ':desc' => $description,
            ':deb'  => $dateDebut,
            ':fin'  => $dateFin,
            ':resp' => $responsableId,
            ':eid'  => $entrepriseId,
        ]);
        $newChantierId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare("
            INSERT INTO utilisateur_chantiers (utilisateur_id, chantier_id, entreprise_id)
            VALUES (:uid, :cid, :eid)
        ");
        foreach ($chefIds as $uid) {
            $ins->execute([':uid' => $uid, ':cid' => $newChantierId, ':eid' => $entrepriseId]);
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
    // error_log($e->getMessage());
    $_SESSION['flash'] = "Erreur serveur lors de l'enregistrement.";
    header("Location: chantiers_admin.php?success=error");
    exit;
}
