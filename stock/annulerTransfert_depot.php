<?php
declare(strict_types=1);

// Fichier: /stock/annulerTransfert_depot.php
require_once __DIR__ . '/../config/init.php';

if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? '') !== 'depot') {
    $_SESSION['error_message'] = "Accès refusé.";
    header("Location: /stock/stock_depot.php");
    exit;
}

$user   = $_SESSION['utilisateurs'];
$userId = (int)($user['id'] ?? 0);
$ENT_ID = (int)($user['entreprise_id'] ?? 0);

if (!$ENT_ID) {
    $_SESSION['error_message'] = "Contexte entreprise manquant.";
    header("Location: /stock/stock_depot.php");
    exit;
}

/* 1) Dépôt du responsable (dans l’entreprise) */
$sql = "SELECT id, nom FROM depots WHERE responsable_id = :uid AND entreprise_id = :eid";
$stmt = $pdo->prepare($sql);
$stmt->execute([':uid' => $userId, ':eid' => $ENT_ID]);
$depot = $stmt->fetch(PDO::FETCH_ASSOC);

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

    /* 3) Charger + verrouiller la demande (dest = CE dépôt, article ∈ entreprise)
       ⚠️ Utiliser deux noms de paramètres différents : :eid1 et :eid2
    */
    $stmt = $pdo->prepare("
        SELECT t.*, s.nom AS article_nom
        FROM transferts_en_attente t
        JOIN stock  s     ON s.id = t.article_id AND s.entreprise_id = :eid1
        JOIN depots d_dest ON d_dest.id = t.destination_id
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
        $_SESSION['error_message'] = "Transfert introuvable, non autorisé ou déjà traité.";
        header("Location: /stock/stock_depot.php");
        exit;
    }

    // Données utiles
    $articleId   = (int)$transfert['article_id'];
    $quantite    = (int)$transfert['quantite'];
    $articleNom  = (string)($transfert['article_nom'] ?? '');
    $demandeurId = (int)$transfert['demandeur_id'];
    $sourceType  = (string)$transfert['source_type']; // 'depot' | 'chantier'
    $sourceId    = isset($transfert['source_id']) ? (int)$transfert['source_id'] : 0;

    /* 4) Si source = dépôt → on remet la quantité (borné entreprise) */
    if ($sourceType === 'depot' && $sourceId) {
        $stmt = $pdo->prepare("
            UPDATE stock_depots sd
            JOIN depots d ON d.id = sd.depot_id AND d.entreprise_id = :eid
               SET sd.quantite = sd.quantite + :qte
             WHERE sd.stock_id = :sid AND sd.depot_id = :did
        ");
        $stmt->execute([
            ':eid' => $ENT_ID,
            ':qte' => $quantite,
            ':sid' => $articleId,
            ':did' => $sourceId
        ]);
    }
    // Si source = chantier : rien à remettre.

    /* 5) Historique (refus) — destination = ce dépôt */
    $stmtMv = $pdo->prepare("
        INSERT INTO stock_mouvements
          (stock_id, type, source_type, source_id, dest_type, dest_id,
           quantite, statut, utilisateur_id, entreprise_id, created_at)
        VALUES
          (:stock_id, 'transfert', :src_type, :src_id, 'depot', :dest_id,
           :qte, 'refuse', :user_id, :ent_id, NOW())
    ");
    $stmtMv->execute([
        ':stock_id' => $articleId,
        ':src_type' => $sourceType,
        ':src_id'   => ($sourceId ?: 0), // 0 pour dépôt si tu préfères, ou NULL si la colonne l’accepte
        ':dest_id'  => $depotId,
        ':qte'      => $quantite,
        ':user_id'  => $userId,
        ':ent_id'   => $ENT_ID,
    ]);

    /* 6) Supprimer la demande + notifier — AVEC entreprise_id */
    $stmt = $pdo->prepare("DELETE FROM transferts_en_attente WHERE id = :tid");
    $stmt->execute([':tid' => $transfertId]);

    $message = "❌ Le dépôt a refusé le transfert de {$quantite} x {$articleNom}.";
    $stmt = $pdo->prepare("
        INSERT INTO notifications (utilisateur_id, message, entreprise_id, created_at)
        VALUES (:uid, :msg, :ent, NOW())
    ");
    $stmt->execute([
        ':uid' => $demandeurId,
        ':msg' => $message,
        ':ent' => $ENT_ID,
    ]);

    $pdo->commit();
    $_SESSION['success_message'] = "Transfert refusé avec succès.";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['error_message'] = "Erreur : " . $e->getMessage();
}

header("Location: /stock/stock_depot.php");
exit;
