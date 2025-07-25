<?php
require_once "./config/init.php";

if (!isset($_SESSION['utilisateurs']) || $_SESSION['utilisateurs']['fonction'] !== 'chef') {
    $_SESSION['error_message'] = "Accès refusé.";
    header("Location: stock_chef.php");
    exit;
}

$chefId = $_SESSION['utilisateurs']['id'];
$stmt = $pdo->prepare("SELECT chantier_id FROM utilisateur_chantiers WHERE utilisateur_id = ?");
$stmt->execute([$chefId]);
$chantierIds = $stmt->fetchAll(PDO::FETCH_COLUMN);


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfert_id']) && $chantierId) {
    $transfertId = (int)$_POST['transfert_id'];

    $stmt = $pdo->prepare("
        SELECT * FROM transferts_en_attente 
        WHERE id = ? AND statut = 'en_attente' 
        AND destination_type = 'chantier' AND destination_id = ?
    ");
    $stmt->execute([$transfertId, $chantierId]);
    $transfert = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$transfert) {
        $_SESSION['error_message'] = "Transfert introuvable ou non autorisé.";
        header("Location: stock_chef.php");
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Si source = dépôt, restituer
        if ($transfert['source_type'] === 'depot') {
            $stmt = $pdo->prepare("UPDATE stock_depots SET quantite = quantite + ? WHERE stock_id = ? AND depot_id = ?");
            $stmt->execute([$transfert['quantite'], $transfert['article_id'], $transfert['source_id']]);
        }

        // Supprimer le transfert
        $stmt = $pdo->prepare("DELETE FROM transferts_en_attente WHERE id = ?");
        $stmt->execute([$transfertId]);

        // Notifier le demandeur
        $message = "❌ Le chantier a refusé le transfert de {$transfert['quantite']} x article #{$transfert['article_id']}.";
        $stmt = $pdo->prepare("INSERT INTO notifications (utilisateur_id, message) VALUES (?, ?)");
        $stmt->execute([$transfert['demandeur_id'], $message]);

        $pdo->commit();

        $_SESSION['success_message'] = "Transfert refusé avec succès.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = "Erreur : " . $e->getMessage();
    }
}

header("Location: stock_chef.php");
exit;
