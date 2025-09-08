<?php
// Fichier : /stock/annulerTransfert_chef.php (corrigé)
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';

if (empty($_SESSION['utilisateurs'])) {
    $_SESSION['error_message'] = "Session expirée.";
    header("Location: stock_chef.php");
    exit;
}

$user   = $_SESSION['utilisateurs'];
$chefId = (int)($user['id'] ?? 0);
$role   = (string)($user['fonction'] ?? '');
$ENT_ID = (int)($user['entreprise_id'] ?? 0);

if ($role !== 'chef' && $role !== 'administrateur') {
    $_SESSION['error_message'] = "Accès refusé.";
    header("Location: stock_chef.php");
    exit;
}

/* Chantiers du chef (pour borner l’action) */
$stmt = $pdo->prepare("SELECT chantier_id FROM utilisateur_chantiers WHERE utilisateur_id = ?");
$stmt->execute([$chefId]);
$chantierIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

if ($role === 'chef' && empty($chantierIds)) {
    $_SESSION['error_message'] = "Aucun chantier associé.";
    header("Location: stock_chef.php");
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['transfert_id'])) {
    $transfertId = (int)$_POST['transfert_id'];

    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->beginTransaction();

        // Récupérer + verrouiller la demande (bornée entreprise via stock)
        $sql = "
            SELECT t.*, s.nom AS article_nom, s.entreprise_id AS ent_article,
                   d.entreprise_id AS ent_depot_src,
                   c_src.entreprise_id AS ent_ch_src,
                   c_dst.entreprise_id AS ent_ch_dst
            FROM transferts_en_attente t
            JOIN stock s              ON s.id = t.article_id AND s.entreprise_id = :ent
            LEFT JOIN depots d        ON (t.source_type='depot'    AND d.id = t.source_id)
            LEFT JOIN chantiers c_src ON (t.source_type='chantier' AND c_src.id = t.source_id)
            LEFT JOIN chantiers c_dst ON (t.destination_type='chantier' AND c_dst.id = t.destination_id)
            WHERE t.id = :tid
              AND t.statut = 'en_attente'
              AND t.destination_type = 'chantier'
            FOR UPDATE
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':tid' => $transfertId, ':ent' => $ENT_ID]);
        $t = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$t) {
            throw new RuntimeException("Transfert introuvable, non autorisé ou déjà traité.");
        }

        $articleId      = (int)$t['article_id'];
        $quantite       = (int)$t['quantite'];
        $demandeurId    = (int)$t['demandeur_id'];
        $sourceType     = (string)$t['source_type'];     // 'depot' | 'chantier'
        $sourceId       = isset($t['source_id']) ? (int)$t['source_id'] : 0;
        $destChantierId = (int)$t['destination_id'];
        $articleNom     = (string)$t['article_nom'];

        // Cohérence multi-entreprise
        if ($ENT_ID <= 0 || (int)$t['ent_article'] !== $ENT_ID) {
            throw new RuntimeException("Article non autorisé pour cette entreprise.");
        }
        if ($sourceType === 'depot') {
            if ((int)$t['ent_depot_src'] !== $ENT_ID) {
                throw new RuntimeException("Dépôt source non autorisé.");
            }
        } else {
            if ((int)$t['ent_ch_src'] !== $ENT_ID) {
                throw new RuntimeException("Chantier source non autorisé.");
            }
        }
        if ((int)$t['ent_ch_dst'] !== $ENT_ID) {
            throw new RuntimeException("Chantier destination non autorisé.");
        }

        // Le chef ne peut refuser que sur ses chantiers (admin passe)
        if ($role === 'chef' && !in_array($destChantierId, $chantierIds, true)) {
            throw new RuntimeException("Vous ne pouvez refuser que sur vos chantiers.");
        }

        // Restitution si la source était un dépôt (souvent décrémenté à l’envoi)
        if ($sourceType === 'depot' && $sourceId) {
            $upd = $pdo->prepare("
                UPDATE stock_depots sd
                JOIN depots d ON d.id = sd.depot_id AND d.entreprise_id = :ent
                   SET sd.quantite = sd.quantite + :qte
                 WHERE sd.stock_id = :sid AND sd.depot_id = :did
            ");
            $upd->execute([
                ':qte' => $quantite, ':sid' => $articleId, ':did' => $sourceId, ':ent' => $ENT_ID
            ]);
        }

        // Historique : refus par le chantier
        $stmtMv = $pdo->prepare("
            INSERT INTO stock_mouvements
              (stock_id, type, source_type, source_id, dest_type, dest_id,
               quantite, statut, utilisateur_id, entreprise_id, created_at)
            VALUES
              (:stock_id, 'transfert', :src_type, :src_id, 'chantier', :dest_id,
               :qte, 'refuse', :user_id, :ent_id, NOW())
        ");
        $stmtMv->execute([
            ':stock_id' => $articleId,
            ':src_type' => $sourceType,
            ':src_id'   => ($sourceType === 'chantier' ? $sourceId : $sourceId), // ok si 0 pour dépôt
            ':dest_id'  => $destChantierId,
            ':qte'      => $quantite,
            ':user_id'  => $chefId,
            ':ent_id'   => $ENT_ID,
        ]);

        // Suppression de la demande
        $stmtDel = $pdo->prepare("DELETE FROM transferts_en_attente WHERE id = ?");
        $stmtDel->execute([$transfertId]);

        // Notification au demandeur — AVEC entreprise_id (FK)
        $message = "❌ Le chantier a refusé le transfert de {$quantite} x {$articleNom}.";
        $stmtNotif = $pdo->prepare("
            INSERT INTO notifications (utilisateur_id, message, entreprise_id, created_at)
            VALUES (:uid, :msg, :ent, NOW())
        ");
        $stmtNotif->execute([
            ':uid' => $demandeurId,
            ':msg' => $message,
            ':ent' => $ENT_ID,
        ]);

        $pdo->commit();

        $_SESSION['success_message'] = "Transfert refusé avec succès.";
        // Reviens sur le bon chantier si possible
        header("Location: stock_chef.php?chantier_id={$destChantierId}");
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error_message'] = "Erreur : " . $e->getMessage();
    }
}

header("Location: stock_chef.php");
exit;
