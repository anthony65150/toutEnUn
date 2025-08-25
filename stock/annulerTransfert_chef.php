<?php
// Fichier : /stock/annulerTransfert_chef.php
require_once __DIR__ . '/../config/init.php';

if (!isset($_SESSION['utilisateurs']) || $_SESSION['utilisateurs']['fonction'] !== 'chef') {
    $_SESSION['error_message'] = "Accès refusé.";
    header("Location: stock_chef.php");
    exit;
}

$chefId = (int)$_SESSION['utilisateurs']['id'];

// Récupère les chantiers du chef
$stmt = $pdo->prepare("SELECT chantier_id FROM utilisateur_chantiers WHERE utilisateur_id = ?");
$stmt->execute([$chefId]);
$chantierIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($chantierIds)) {
    $_SESSION['error_message'] = "Aucun chantier associé.";
    header("Location: stock_chef.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfert_id'])) {
    $transfertId = (int)$_POST['transfert_id'];

    // placeholders pour le IN (...)
    $placeholders = implode(',', array_fill(0, count($chantierIds), '?'));

    try {
        // Démarrer la transaction AVANT le SELECT ... FOR UPDATE
        $pdo->beginTransaction();

        // Verrouiller le transfert si destination = un chantier du chef et statut en attente
        $sql = "
            SELECT t.*, s.nom AS article_nom
            FROM transferts_en_attente t
            JOIN stock s ON t.article_id = s.id
            WHERE t.id = ?
              AND t.statut = 'en_attente'
              AND t.destination_type = 'chantier'
              AND t.destination_id IN ($placeholders)
            FOR UPDATE
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$transfertId], $chantierIds));
        $transfert = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$transfert) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Transfert introuvable, non autorisé, ou déjà traité.";
            header("Location: stock_chef.php");
            exit;
        }

        // Données utiles
        $articleId       = (int)$transfert['article_id'];
        $quantite        = (int)$transfert['quantite'];
        $articleNom      = $transfert['article_nom'];
        $demandeurId     = (int)$transfert['demandeur_id'];
        $sourceType      = $transfert['source_type'];          // 'depot' | 'chantier'
        $sourceId        = isset($transfert['source_id']) ? (int)$transfert['source_id'] : null;
        $destChantierId  = (int)$transfert['destination_id'];  // chantier du chef

        // Si source = dépôt, on restitue au dépôt (car décrémenté à l’envoi)
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
        // Si source = chantier, rien à remettre : pas de décrémentation faite à l’envoi dans ta logique actuelle.

        // Historique (refus par le chef)
        // Table attendue: stock_mouvements(stock_id, type, source_type, source_id, dest_type, dest_id, quantite, statut, utilisateur_id, created_at)
        $stmtMv = $pdo->prepare("
            INSERT INTO stock_mouvements
                (stock_id, type, source_type, source_id, dest_type, dest_id, quantite, statut, utilisateur_id, created_at)
            VALUES
                (:stock_id, 'transfert', :src_type, :src_id, 'chantier', :dest_id, :qte, 'refuse', :user_id, NOW())
        ");
        $stmtMv->execute([
            ':stock_id' => $articleId,
            ':src_type' => $sourceType,
            ':src_id'   => ($sourceType === 'chantier') ? $sourceId : null, // null si dépôt (garde tel quel si tu ne veux pas tracer l'ID dépôt)
            ':dest_id'  => $destChantierId,
            ':qte'      => $quantite,
            ':user_id'  => $chefId,
        ]);

        // Supprimer la demande en attente
        $stmt = $pdo->prepare("DELETE FROM transferts_en_attente WHERE id = ?");
        $stmt->execute([$transfertId]);

        // Notifier le demandeur
        $message = "❌ Le chantier a refusé le transfert de {$quantite} x {$articleNom}.";
        $stmt = $pdo->prepare("INSERT INTO notifications (utilisateur_id, message) VALUES (?, ?)");
        $stmt->execute([$demandeurId, $message]);

        // Commit & UI
        $pdo->commit();

        $_SESSION['success_message'] = "Transfert refusé avec succès.";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error_message'] = "Erreur : " . $e->getMessage();
    }
}

header("Location: stock_chef.php");
exit;
