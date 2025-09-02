<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';

if (!isset($_SESSION['utilisateurs']) || (($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur')) {
    header("Location: ../connexion.php");
    exit;
}

$entrepriseId = (int)($_SESSION['entreprise_id'] ?? 0);
if ($entrepriseId <= 0) {
    http_response_code(403);
    exit('Entreprise non sélectionnée');
}

/* ====== Inputs ====== */
$depotId        = (int)($_POST['id'] ?? 0); // <-- corrige: avant c'était depot_id
$nom            = trim($_POST['nom'] ?? '');
$responsable_id = ($_POST['responsable_id'] ?? '') !== '' ? (int)$_POST['responsable_id'] : null;

if ($nom === '') {
    header("Location: ./depots_admin.php?error=nom_obligatoire");
    exit;
}

/* Vérifier que le responsable appartient à la même entreprise (si fourni) */
if ($responsable_id !== null) {
    $chkResp = $pdo->prepare("SELECT id FROM utilisateurs WHERE id = :uid AND entreprise_id = :eid");
    $chkResp->execute([':uid' => $responsable_id, ':eid' => $entrepriseId]);
    if (!$chkResp->fetch()) {
        header("Location: ./depots_admin.php?error=responsable_hors_entreprise");
        exit;
    }
}

try {
    if ($depotId > 0) {
        /* ===== UPDATE =====
           Vérifier l'ownership du dépôt dans cette entreprise */
        $own = $pdo->prepare("SELECT id FROM depots WHERE id = :id AND entreprise_id = :eid");
        $own->execute([':id' => $depotId, ':eid' => $entrepriseId]);
        if (!$own->fetch()) {
            header("Location: ./depots_admin.php?error=depot_introuvable");
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE depots
               SET nom = :nom,
                   responsable_id = :resp
             WHERE id = :id
               AND entreprise_id = :eid
        ");
        $stmt->bindValue(':nom', $nom, PDO::PARAM_STR);
        $stmt->bindValue(':resp', $responsable_id, $responsable_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':id', $depotId, PDO::PARAM_INT);
        $stmt->bindValue(':eid', $entrepriseId, PDO::PARAM_INT);
        $stmt->execute();

        header("Location: ./depots_admin.php?success=update&highlight={$depotId}");
        exit;
    } else {
        /* ===== CREATE ===== */
        $stmt = $pdo->prepare("
            INSERT INTO depots (nom, responsable_id, entreprise_id)
            VALUES (:nom, :resp, :eid)
        ");
        $stmt->bindValue(':nom', $nom, PDO::PARAM_STR);
        $stmt->bindValue(':resp', $responsable_id, $responsable_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':eid', $entrepriseId, PDO::PARAM_INT);
        $stmt->execute();

        $newId = (int)$pdo->lastInsertId();
        header("Location: ./depots_admin.php?success=create&highlight={$newId}");
        exit;
    }
} catch (PDOException $e) {
    // Erreur d'unicité (si vous avez un index UNIQUE (entreprise_id, nom))
    if (($e->errorInfo[1] ?? 0) == 1062) {
        header("Location: ./depots_admin.php?error=nom_deja_utilise");
        exit;
    }
    // Autre erreur
    header("Location: ./depots_admin.php?error=server");
    exit;
}
