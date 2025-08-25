<?php
require_once __DIR__ . '/../config/init.php';

if (!isset($_SESSION['utilisateurs'])) {
    header("Location: /connexion.php");
    exit;
}

$userId = (int)($_SESSION['utilisateurs']['id'] ?? 0);

// Récupérer le dépôt du responsable connecté
$stmtDepot = $pdo->prepare("SELECT id FROM depots WHERE responsable_id = ?");
$stmtDepot->execute([$userId]);
$depot = $stmtDepot->fetch(PDO::FETCH_ASSOC);
$depotId = $depot ? (int)$depot['id'] : null;

if (!$depotId) {
    $_SESSION['error_message'] = "Dépôt introuvable pour cet utilisateur.";
    header("Location: /stock/stock_depot.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfert_id'])) {
    $transfertId = (int)$_POST['transfert_id'];

    try {
        // Démarrer la transaction AVANT le SELECT ... FOR UPDATE
        $pdo->beginTransaction();

        // Sélectionner et verrouiller le transfert (destination = ce dépôt, encore en attente)
        $stmt = $pdo->prepare("
            SELECT t.*, s.nom AS article_nom
            FROM transferts_en_attente t
            JOIN stock s ON t.article_id = s.id
            WHERE t.id = ?
              AND t.destination_type = 'depot'
              AND t.destination_id = ?
              AND t.statut = 'en_attente'
            FOR UPDATE
        ");
        $stmt->execute([$transfertId, $depotId]);
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
        $sourceType  = $transfert['source_type'];                 // 'depot' | 'chantier'
        $sourceId    = isset($transfert['source_id']) ? (int)$transfert['source_id'] : null;
        $articleNom  = htmlspecialchars($transfert['article_nom']);

        // ➕ Ajouter au dépôt
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM stock_depots WHERE depot_id = ? AND stock_id = ?");
        $stmtCheck->execute([$depotId, $articleId]);
        $exists = (bool)$stmtCheck->fetchColumn();

        if ($exists) {
            $stmtUpdate = $pdo->prepare("
                UPDATE stock_depots
                SET quantite = quantite + :qte
                WHERE depot_id = :depot AND stock_id = :article
            ");
            $stmtUpdate->execute([
                'qte'     => $quantite,
                'depot'   => $depotId,
                'article' => $articleId
            ]);
        } else {
            $stmtInsert = $pdo->prepare("
                INSERT INTO stock_depots (depot_id, stock_id, quantite)
                VALUES (:depot, :article, :qte)
            ");
            $stmtInsert->execute([
                'depot'   => $depotId,
                'article' => $articleId,
                'qte'     => $quantite
            ]);
        }

        // 🔻 Retirer de la source si la source est un chantier
        if ($sourceType === 'chantier' && $sourceId) {
            $stmtUpdateChantier = $pdo->prepare("
                UPDATE stock_chantiers
                SET quantite = GREATEST(quantite - :qte, 0)
                WHERE chantier_id = :chantier AND stock_id = :article
            ");
            $stmtUpdateChantier->execute([
                'qte'      => $quantite,
                'chantier' => $sourceId,
                'article'  => $articleId
            ]);
        }
        // Si source = dépôt, la décrémentation a normalement été faite à l’envoi

        // 🧾 Historique du mouvement (validation par le dépôt)
        $commentaire = $transfert['commentaire'] ?? null;

        $stmtMv = $pdo->prepare("
            INSERT INTO stock_mouvements
                (stock_id, type, source_type, source_id, dest_type, dest_id, quantite, statut, commentaire, utilisateur_id, demandeur_id, created_at)
            VALUES
                (:stock_id, 'transfert', :src_type, :src_id, 'depot', :dest_id, :qte, 'valide', :commentaire, :validateur_id, :demandeur_id, NOW())
        ");
        $stmtMv->execute([
            ':stock_id'      => $articleId,
            ':src_type'      => $sourceType,   // 'depot' | 'chantier'
            ':src_id'        => $sourceId,     // garder l'ID même si dépôt
            ':dest_id'       => $depotId,      // destination = ce dépôt
            ':qte'           => $quantite,
            ':commentaire'   => $commentaire,
            ':validateur_id' => $userId,       // celui qui valide
            ':demandeur_id'  => $demandeurId,  // qui a demandé
        ]);

        // ✅ Terminer : supprimer le transfert en attente
        $stmtDelete = $pdo->prepare("DELETE FROM transferts_en_attente WHERE id = ?");
        $stmtDelete->execute([$transfertId]);

        // 🔔 Notifier le demandeur
        $message = "✅ Le transfert de {$quantite} x {$articleNom} a été validé par le dépôt.";
        $stmtNotif = $pdo->prepare("INSERT INTO notifications (utilisateur_id, message) VALUES (?, ?)");
        $stmtNotif->execute([$demandeurId, $message]);

        $pdo->commit();

        $_SESSION['success_message']     = "Transfert validé avec succès.";
        $_SESSION['highlight_stock_id']  = $articleId;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error_message'] = "Erreur lors de la validation : " . $e->getMessage();
    }
} else {
    $_SESSION['error_message'] = "Requête invalide.";
}

// Redirection (pas de chantier_id ici)
header("Location: /stock/stock_depot.php");
exit;
