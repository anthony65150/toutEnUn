<?php
require_once "./config/init.php";

if (!isset($_SESSION['utilisateurs'])) {
    header("Location: connexion.php");
    exit;
}

$userId = $_SESSION['utilisateurs']['id'];
$stmtDepot = $pdo->prepare("SELECT id FROM depots WHERE responsable_id = ?");
$stmtDepot->execute([$userId]);
$depot = $stmtDepot->fetch(PDO::FETCH_ASSOC);
$depotId = $depot ? (int)$depot['id'] : null;

if (!$depotId) {
    $_SESSION['error_message'] = "Dépôt introuvable pour cet utilisateur.";
    header("Location: stock_depot.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfert_id'])) {
    $transfertId = (int)$_POST['transfert_id'];

    $stmt = $pdo->prepare("
        SELECT t.*, s.nom AS article_nom
        FROM transferts_en_attente t
        JOIN stock s ON t.article_id = s.id
        WHERE t.id = ? AND t.destination_type = 'depot' AND t.destination_id = ?
    ");
    $stmt->execute([$transfertId, $depotId]);
    $transfert = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($transfert) {
        $articleId = $transfert['article_id'];
        $quantite = (int)$transfert['quantite'];
        $demandeurId = $transfert['demandeur_id'];
        $sourceType = $transfert['source_type'];
        $sourceId = $transfert['source_id'];

        try {
            $pdo->beginTransaction();

            if ($sourceType === 'chantier') {
                // Ajouter au dépôt (uniquement si source = chantier)
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM stock_depots WHERE depot_id = ? AND stock_id = ?");
                $stmtCheck->execute([$depotId, $articleId]);
                $exists = $stmtCheck->fetchColumn();

                if ($exists) {
                    $stmtUpdate = $pdo->prepare("UPDATE stock_depots SET quantite = quantite + :qte WHERE depot_id = :depot AND stock_id = :article");
                    $stmtUpdate->execute(['qte' => $quantite, 'depot' => $depotId, 'article' => $articleId]);
                } else {
                    $stmtInsert = $pdo->prepare("INSERT INTO stock_depots (depot_id, stock_id, quantite) VALUES (:depot, :article, :qte)");
                    $stmtInsert->execute(['depot' => $depotId, 'article' => $articleId, 'qte' => $quantite]);
                }

                // Retirer du chantier source
                $stmtUpdateChantier = $pdo->prepare("UPDATE stock_chantiers SET quantite = GREATEST(quantite - :qte, 0) WHERE chantier_id = :chantier AND stock_id = :article");
                $stmtUpdateChantier->execute(['qte' => $quantite, 'chantier' => $sourceId, 'article' => $articleId]);
            }

            // Supprimer le transfert en attente
            $stmtDelete = $pdo->prepare("DELETE FROM transferts_en_attente WHERE id = ?");
            $stmtDelete->execute([$transfertId]);

            // Notifier
            $message = "✅ Le transfert de {$quantite} x {$transfert['article_nom']} a été validé par le dépôt.";
            $stmtNotif = $pdo->prepare("INSERT INTO notifications (utilisateur_id, message) VALUES (?, ?)");
            $stmtNotif->execute([$demandeurId, $message]);

            $pdo->commit();
            $_SESSION['success_message'] = "Transfert validé avec succès.";
            
            // ✅ Le surlignage avec $_SESSION
            $_SESSION['highlight_stock_id'] = $articleId;
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Erreur lors de la validation : " . $e->getMessage();
        }
    } else {
        $_SESSION['error_message'] = "Transfert introuvable.";
    }
} else {
    $_SESSION['error_message'] = "Requête invalide.";
}

header("Location: stock_depot.php?chantier_id=" . ($chantierId ?? ''));
exit;
