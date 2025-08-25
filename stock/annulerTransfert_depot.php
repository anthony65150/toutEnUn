<?php
// Fichier: /stock/annulerTransfert_depot.php (ou refuserReception_depot.php)
require_once __DIR__ . '/../config/init.php';

if (!isset($_SESSION['utilisateurs']) || $_SESSION['utilisateurs']['fonction'] !== 'depot') {
    $_SESSION['error_message'] = "Accès refusé.";
    header("Location: stock_depot.php");
    exit;
}

// Récupérer l’ID du dépôt associé à l'utilisateur (responsable)
$userId = (int)$_SESSION['utilisateurs']['id'];
$stmt = $pdo->prepare("SELECT id FROM depots WHERE responsable_id = ?");
$stmt->execute([$userId]);
$depot = $stmt->fetch(PDO::FETCH_ASSOC);
$depotId = $depot['id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfert_id']) && $depotId) {
    $transfertId = (int)$_POST['transfert_id'];

    try {
        // Démarrer la transaction AVANT le SELECT ... FOR UPDATE
        $pdo->beginTransaction();

        // On verrouille la demande ciblant CE dépôt, encore en attente
        $stmt = $pdo->prepare("
            SELECT t.*, s.nom AS article_nom
            FROM transferts_en_attente t
            JOIN stock s ON t.article_id = s.id
            WHERE t.id = ?
              AND t.statut = 'en_attente'
              AND t.destination_type = 'depot'
              AND t.destination_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$transfertId, $depotId]);
        $transfert = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$transfert) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Transfert introuvable, non autorisé ou déjà traité.";
            header("Location: stock_depot.php");
            exit;
        }

        // Données utiles
        $articleId   = (int)$transfert['article_id'];
        $quantite    = (int)$transfert['quantite'];
        $articleNom  = $transfert['article_nom'];
        $demandeurId = (int)$transfert['demandeur_id'];
        $sourceType  = $transfert['source_type'];                 // 'depot' | 'chantier'
        $sourceId    = isset($transfert['source_id']) ? (int)$transfert['source_id'] : null;

        // Si source = dépôt → on avait déjà décrémenté à l’envoi → on remet
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
        // Si source = chantier → pas de remise ici (pas de décrément à l’envoi)

        // Historique : refus par le dépôt
        // NOTE: si tu veux tracer l'ID du dépôt source aussi quand sourceType='depot',
        // mets ':src_id' => $sourceId (au lieu de null).
        $stmtMv = $pdo->prepare("
            INSERT INTO stock_mouvements
                (stock_id, type, source_type, source_id, dest_type, dest_id, quantite, statut, utilisateur_id, created_at)
            VALUES
                (:stock_id, 'transfert', :src_type, :src_id, 'depot', :dest_id, :qte, 'refuse', :user_id, NOW())
        ");
        $stmtMv->execute([
            ':stock_id' => $articleId,
            ':src_type' => $sourceType,
            ':src_id'   => ($sourceType === 'chantier') ? $sourceId : null, // mets $sourceId si tu veux le garder aussi pour dépôt
            ':dest_id'  => $depotId,
            ':qte'      => $quantite,
            ':user_id'  => $userId,
        ]);

        // Supprimer la demande
        $stmt = $pdo->prepare("DELETE FROM transferts_en_attente WHERE id = ?");
        $stmt->execute([$transfertId]);

        // Notifier le demandeur
        $message = "❌ Le dépôt a refusé le transfert de {$quantite} x {$articleNom}.";
        $stmt = $pdo->prepare("INSERT INTO notifications (utilisateur_id, message) VALUES (?, ?)");
        $stmt->execute([$demandeurId, $message]);

        $pdo->commit();

        $_SESSION['success_message'] = "Transfert refusé avec succès.";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error_message'] = "Erreur : " . $e->getMessage();
    }
}

header("Location: stock_depot.php");
exit;
