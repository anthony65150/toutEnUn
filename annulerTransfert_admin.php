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
        $_SESSION['error_message'] = "Transfert introuvable ou déjà traité.";
        header("Location: stock_admin.php");
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Si source = dépôt, le stock a déjà été décrémenté → on le remet
        if ($transfert['source_type'] === 'depot') {
            $stmt = $pdo->prepare("UPDATE stock_depots SET quantite = quantite + ? WHERE stock_id = ? AND depot_id = ?");
            $stmt->execute([$transfert['quantite'], $transfert['article_id'], $transfert['source_id']]);
        }

        // Supprimer le transfert
        $stmt = $pdo->prepare("DELETE FROM transferts_en_attente WHERE id = ?");
        $stmt->execute([$transfertId]);

        // Notifier le demandeur
       $message = "❌ Le transfert de {$transfert['quantite']} x {$transfert['article_nom']} a été annulé par l’administrateur.";

        $stmt = $pdo->prepare("INSERT INTO notifications (utilisateur_id, message) VALUES (?, ?)");
        $stmt->execute([$transfert['demandeur_id'], $message]);

        $pdo->commit();

        $_SESSION['success_message'] = "Transfert annulé avec succès.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = "Erreur lors de l’annulation : " . $e->getMessage();
    }
}

header("Location: stock_admin.php");
exit;
