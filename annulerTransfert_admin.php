<?php
require_once "./config/init.php";

if (!isset($_SESSION['utilisateurs']) || $_SESSION['utilisateurs']['fonction'] !== 'administrateur') {
    $_SESSION['error_message'] = "Accès refusé.";
    header("Location: stock_admin.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfert_id'])) {
    $transfertId = (int)$_POST['transfert_id'];
    $adminId     = (int)($_SESSION['utilisateurs']['id'] ?? 0);

    try {
        // 1) Transaction + SELECT ... FOR UPDATE pour éviter les doubles traitements
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT t.*, s.nom AS article_nom
            FROM transferts_en_attente t
            JOIN stock s ON t.article_id = s.id
            WHERE t.id = ?
              AND t.statut = 'en_attente'
            FOR UPDATE
        ");
        $stmt->execute([$transfertId]);
        $transfert = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$transfert) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Transfert introuvable ou déjà traité.";
            header("Location: stock_admin.php");
            exit;
        }

        $articleId       = (int)$transfert['article_id'];
        $quantite        = (int)$transfert['quantite'];
        $sourceType      = $transfert['source_type'];          // 'depot' | 'chantier'
        $sourceId        = isset($transfert['source_id']) ? (int)$transfert['source_id'] : null;
        $destinationType = $transfert['destination_type'];     // 'depot' | 'chantier'
        $destinationId   = isset($transfert['destination_id']) ? (int)$transfert['destination_id'] : null;
        $demandeurId     = (int)$transfert['demandeur_id'];
        $articleNom      = $transfert['article_nom'];

        // 2) Si la source = dépôt, on remet la quantité (car retirée à l'envoi)
        if ($sourceType === 'depot' && $sourceId) {
            $stmt = $pdo->prepare("
                UPDATE stock_depots
                SET quantite = quantite + :qte
                WHERE stock_id = :article AND depot_id = :depot
            ");
            $stmt->execute([
                ':qte'     => $quantite,
                ':article' => $articleId,
                ':depot'   => $sourceId
            ]);
        }
        // (Si source = chantier, rien à remettre ici : la décrémentation n'avait pas eu lieu)

        // 3) Historique : on trace l'annulation
        // Table attendue: stock_mouvements(stock_id, type, source_type, source_id, dest_type, dest_id, quantite, statut, utilisateur_id, created_at)
        $stmtMv = $pdo->prepare("
            INSERT INTO stock_mouvements
                (stock_id, type, source_type, source_id, dest_type, dest_id, quantite, statut, utilisateur_id, created_at)
            VALUES
                (:stock_id, 'transfert', :src_type, :src_id, :dest_type, :dest_id, :qte, 'annule', :user_id, NOW())
        ");
        $stmtMv->execute([
            ':stock_id'  => $articleId,
            ':src_type'  => $sourceType,
            ':src_id'    => ($sourceType === 'chantier') ? $sourceId : null,              // null si dépôt
            ':dest_type' => $destinationType,
            ':dest_id'   => ($destinationType === 'chantier') ? $destinationId : null,    // null si dépôt
            ':qte'       => $quantite,
            ':user_id'   => $adminId,
        ]);

        // 4) Supprimer la demande en attente
        $stmt = $pdo->prepare("DELETE FROM transferts_en_attente WHERE id = ?");
        $stmt->execute([$transfertId]);

        // 5) Notifier le demandeur
        $message = "❌ Le transfert de {$quantite} x {$articleNom} a été annulé par l’administrateur.";
        $stmt = $pdo->prepare("INSERT INTO notifications (utilisateur_id, message) VALUES (?, ?)");
        $stmt->execute([$demandeurId, $message]);

        // 6) Commit & UI
        $pdo->commit();
        $_SESSION['success_message'] = "Transfert annulé avec succès.";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error_message'] = "Erreur lors de l’annulation : " . $e->getMessage();
    }
}

header("Location: stock_admin.php");
exit;
