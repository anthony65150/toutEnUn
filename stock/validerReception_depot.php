<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';

if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? '') !== 'depot') {
    $_SESSION['error_message'] = "Accès refusé.";
    header("Location: /stock/stock_depot.php");
    exit;
}

$user    = $_SESSION['utilisateurs'];
$userId  = (int)($user['id'] ?? 0);
$ENT_ID  = (int)($user['entreprise_id'] ?? 0);

if (!$ENT_ID) {
    $_SESSION['error_message'] = "Contexte entreprise manquant.";
    header("Location: /stock/stock_depot.php");
    exit;
}

/* 1) Dépôt du responsable (dans l'entreprise) */
$sql = "SELECT id, nom FROM depots WHERE responsable_id = :uid AND entreprise_id = :eid";
$stmtDepot = $pdo->prepare($sql);
$stmtDepot->execute([':uid' => $userId, ':eid' => $ENT_ID]);
$depot = $stmtDepot->fetch(PDO::FETCH_ASSOC);

$depotId = $depot ? (int)$depot['id'] : 0;
if (!$depotId) {
    $_SESSION['error_message'] = "Dépôt introuvable pour cet utilisateur.";
    header("Location: /stock/stock_depot.php");
    exit;
}

/* 2) Entrée */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !isset($_POST['transfert_id'])) {
    $_SESSION['error_message'] = "Requête invalide.";
    header("Location: /stock/stock_depot.php");
    exit;
}
$transfertId = (int)$_POST['transfert_id'];

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();

    /* 3) Charger + verrouiller le transfert (dest = CE dépôt, article de l'entreprise) */
    $stmt = $pdo->prepare("
        SELECT t.*, s.nom AS article_nom
        FROM transferts_en_attente t
        JOIN stock s        ON s.id = t.article_id AND s.entreprise_id = :eid1
        JOIN depots d_dest  ON d_dest.id = t.destination_id
                           AND t.destination_type = 'depot'
                           AND d_dest.entreprise_id = :eid2
        WHERE t.id = :tid
          AND t.destination_id = :did
          AND t.statut = 'en_attente'
        FOR UPDATE
    ");
    $stmt->execute([
        ':eid1' => $ENT_ID,
        ':eid2' => $ENT_ID,
        ':tid'  => $transfertId,
        ':did'  => $depotId
    ]);

    $transfert = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$transfert) {
        $pdo->rollBack();
        $_SESSION['error_message'] = "Transfert introuvable ou déjà traité.";
        header("Location: /stock/stock_depot.php");
        exit;
    }

    $articleId   = (int)$transfert['article_id'];
    $quantite    = (int)$transfert['quantite'];
    $demandeurId = (int)$transfert['demandeur_id'];
    $sourceType  = (string)$transfert['source_type']; // 'depot' | 'chantier'
    $sourceId    = isset($transfert['source_id']) ? (int)$transfert['source_id'] : 0;
    $articleNom  = (string)($transfert['article_nom'] ?? '');

    /* 4) Ajouter au dépôt DEST (upsert) — AVEC entreprise_id */
    $stmtCheck = $pdo->prepare("
        SELECT sd.quantite
        FROM stock_depots sd
        JOIN depots d ON d.id = sd.depot_id AND d.entreprise_id = :eid
        WHERE sd.depot_id = :did AND sd.stock_id = :sid
        FOR UPDATE
    ");
    $stmtCheck->execute([':eid' => $ENT_ID, ':did' => $depotId, ':sid' => $articleId]);
    $exists = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if ($exists) {
        $stmtUpdate = $pdo->prepare("
            UPDATE stock_depots sd
            JOIN depots d ON d.id = sd.depot_id AND d.entreprise_id = :eid
               SET sd.quantite = sd.quantite + :qte
             WHERE sd.depot_id = :did AND sd.stock_id = :sid
        ");
        $stmtUpdate->execute([':eid' => $ENT_ID, ':qte' => $quantite, ':did' => $depotId, ':sid' => $articleId]);
    } else {
        $stmtInsert = $pdo->prepare("
            INSERT INTO stock_depots (entreprise_id, depot_id, stock_id, quantite)
            VALUES (:eid, :did, :sid, :qte)
        ");
        $stmtInsert->execute([':eid' => $ENT_ID, ':did' => $depotId, ':sid' => $articleId, ':qte' => $quantite]);
    }

    /* 4.1) Archiver une éventuelle demande de relevé si l’article revient au dépôt */
    try {
        $pdo->prepare("
            UPDATE stock_alerts
               SET is_read = 1, archived_at = NOW()
             WHERE stock_id = :sid
               AND type = 'hour_meter_request'
               AND archived_at IS NULL
        ")->execute([':sid' => $articleId]);
    } catch (Throwable $e) {
        error_log('archive hour_meter_request on depot reception: '.$e->getMessage());
    }

    /* 5) Retirer de la source si source = chantier (bornage entreprise) */
    if ($sourceType === 'chantier' && $sourceId) {
        $stmtUpdateChantier = $pdo->prepare("
            UPDATE stock_chantiers sc
            JOIN chantiers c ON c.id = sc.chantier_id AND c.entreprise_id = :eid
               SET sc.quantite = GREATEST(sc.quantite - :qte, 0)
             WHERE sc.chantier_id = :cid AND sc.stock_id = :sid
        ");
        $stmtUpdateChantier->execute([
            ':eid' => $ENT_ID,
            ':qte' => $quantite,
            ':cid' => $sourceId,
            ':sid' => $articleId
        ]);
    }

    /* 6) Historique */
    $commentaire = $transfert['commentaire'] ?? null;
    $stmtMv = $pdo->prepare("
        INSERT INTO stock_mouvements
          (stock_id, type, source_type, source_id, dest_type, dest_id,
           quantite, statut, commentaire, utilisateur_id, demandeur_id, entreprise_id, created_at)
        VALUES
          (:stock_id, 'transfert', :src_type, :src_id, 'depot', :dest_id,
           :qte, 'valide', :commentaire, :validateur_id, :demandeur_id, :ent_id, NOW())
    ");
    $stmtMv->execute([
        ':stock_id'      => $articleId,
        ':src_type'      => $sourceType,
        ':src_id'        => $sourceId,
        ':dest_id'       => $depotId,
        ':qte'           => $quantite,
        ':commentaire'   => $commentaire,
        ':validateur_id' => $userId,
        ':demandeur_id'  => $demandeurId,
        ':ent_id'        => $ENT_ID,
    ]);

    /* 7) Supprimer la demande + notifier le demandeur (avec stock_id si dispo) */
    $stmtDelete = $pdo->prepare("DELETE FROM transferts_en_attente WHERE id = :tid");
    $stmtDelete->execute([':tid' => $transfertId]);

    $message = "✅ Le transfert de {$quantite} x {$articleNom} a été validé par le dépôt.";
    try {
        // tentative avec colonne stock_id
        $pdo->prepare("
            INSERT INTO notifications (utilisateur_id, message, entreprise_id, stock_id, created_at)
            VALUES (:uid, :msg, :ent, :sid, NOW())
        ")->execute([
            ':uid' => $demandeurId,
            ':msg' => $message,
            ':ent' => $ENT_ID,
            ':sid' => $articleId,
        ]);
    } catch (Throwable $e) {
        // fallback si la colonne stock_id n'existe pas
        $pdo->prepare("
            INSERT INTO notifications (utilisateur_id, message, entreprise_id, created_at)
            VALUES (:uid, :msg, :ent, NOW())
        ")->execute([
            ':uid' => $demandeurId,
            ':msg' => $message,
            ':ent' => $ENT_ID,
        ]);
    }

    $pdo->commit();

    $_SESSION['success_message']    = "Transfert validé avec succès.";
    $_SESSION['highlight_stock_id'] = $articleId;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['error_message'] = "Erreur lors de la validation : " . $e->getMessage();
}

header("Location: /stock/stock_depot.php");
exit;
