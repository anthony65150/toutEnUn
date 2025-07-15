<?php
require_once "./config/init.php";

if (!isset($_SESSION['utilisateurs']) || $_SESSION['utilisateurs']['fonction'] !== 'administrateur') {
    $_SESSION['error_message'] = "Accès refusé.";
    header("Location: stock_admin.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfert_id'])) {
    $transfertId = (int)$_POST['transfert_id'];

    $stmt = $pdo->prepare("
        SELECT t.*, s.nom AS article_nom
        FROM transferts_en_attente t
        JOIN stock s ON t.article_id = s.id
        WHERE t.id = ? AND t.statut = 'en_attente'
    ");
    $stmt->execute([$transfertId]);
    $transfert = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$transfert) {
        $_SESSION['error_message'] = "Transfert introuvable ou déjà validé.";
        header("Location: stock_admin.php");
        exit;
    }

    $articleId = $transfert['article_id'];
    $quantite = (int)$transfert['quantite'];
    $sourceType = $transfert['source_type'];
    $sourceId = (int)$transfert['source_id'];
    $destinationType = $transfert['destination_type'];
    $destinationId = (int)$transfert['destination_id'];
    $demandeurId = $transfert['demandeur_id'];

    try {
        $pdo->beginTransaction();

        // ➕ Ajouter au destination
        if ($destinationType === 'depot') {
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM stock_depots WHERE depot_id = ? AND stock_id = ?");
            $stmtCheck->execute([$destinationId, $articleId]);
            if ($stmtCheck->fetchColumn()) {
                $stmtUpdate = $pdo->prepare("UPDATE stock_depots SET quantite = quantite + :qte WHERE depot_id = :dest AND stock_id = :article");
                $stmtUpdate->execute(['qte' => $quantite, 'dest' => $destinationId, 'article' => $articleId]);
            } else {
                $stmtInsert = $pdo->prepare("INSERT INTO stock_depots (depot_id, stock_id, quantite) VALUES (:dest, :article, :qte)");
                $stmtInsert->execute(['dest' => $destinationId, 'article' => $articleId, 'qte' => $quantite]);
            }
        } else {
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM stock_chantiers WHERE chantier_id = ? AND stock_id = ?");
            $stmtCheck->execute([$destinationId, $articleId]);
            if ($stmtCheck->fetchColumn()) {
                $stmtUpdate = $pdo->prepare("UPDATE stock_chantiers SET quantite = quantite + :qte WHERE chantier_id = :dest AND stock_id = :article");
                $stmtUpdate->execute(['qte' => $quantite, 'dest' => $destinationId, 'article' => $articleId]);
            } else {
                $stmtInsert = $pdo->prepare("INSERT INTO stock_chantiers (chantier_id, stock_id, quantite) VALUES (:dest, :article, :qte)");
                $stmtInsert->execute(['dest' => $destinationId, 'article' => $articleId, 'qte' => $quantite]);
            }
        }

        // 🔻 Retirer de la source
        if ($sourceType === 'depot') {
            $stmtUpdate = $pdo->prepare("UPDATE stock_depots SET quantite = GREATEST(quantite - :qte, 0) WHERE depot_id = :src AND stock_id = :article");
            $stmtUpdate->execute(['qte' => $quantite, 'src' => $sourceId, 'article' => $articleId]);
        } else {
            $stmtUpdate = $pdo->prepare("UPDATE stock_chantiers SET quantite = GREATEST(quantite - :qte, 0) WHERE chantier_id = :src AND stock_id = :article");
            $stmtUpdate->execute(['qte' => $quantite, 'src' => $sourceId, 'article' => $articleId]);
        }

        // ✅ Supprimer le transfert en attente
        $stmtDelete = $pdo->prepare("DELETE FROM transferts_en_attente WHERE id = ?");
        $stmtDelete->execute([$transfertId]);

        // ➕ Notifier le demandeur
        $message = "✅ Le transfert de {$quantite} x {$transfert['article_nom']} a été validé par l'administrateur.";
        $stmtNotif = $pdo->prepare("INSERT INTO notifications (utilisateur_id, message) VALUES (?, ?)");
        $stmtNotif->execute([$demandeurId, $message]);

        $pdo->commit();

        $_SESSION['success_message'] = "Transfert validé avec succès.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = "Erreur lors de la validation : " . $e->getMessage();
    }
} else {
    $_SESSION['error_message'] = "Requête invalide.";
}

header("Location: stock_admin.php");
exit;
