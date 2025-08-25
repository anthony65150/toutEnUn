<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';

if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? null) !== 'administrateur') {
    $_SESSION['error_message'] = "Accès refusé.";
    header("Location: stock_admin.php");
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !isset($_POST['transfert_id'])) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        echo json_encode(['success' => false, 'message' => "Requête invalide."]);
        exit;
    }
    $_SESSION['error_message'] = "Requête invalide.";
    header("Location: stock_admin.php");
    exit;
}

$transfertId = (int)$_POST['transfert_id'];

// Charger le transfert (encore en attente)
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

$articleId        = (int)$transfert['article_id'];
$quantite         = (int)$transfert['quantite'];
$sourceType       = $transfert['source_type'];         // 'depot' | 'chantier'
$sourceId         = isset($transfert['source_id']) ? (int)$transfert['source_id'] : null;
$destinationType  = $transfert['destination_type'];    // 'depot' | 'chantier'
$destinationId    = isset($transfert['destination_id']) ? (int)$transfert['destination_id'] : null;
$demandeurId      = (int)$transfert['demandeur_id'];
$validatorUserId  = $_SESSION['utilisateurs']['id'] ?? null;

try {
    $pdo->beginTransaction();

    // ➕ Ajout côté destination (verrouillage "FOR UPDATE" optionnel selon moteur)
    if ($destinationType === 'depot') {
        $stmtCheck = $pdo->prepare("SELECT quantite FROM stock_depots WHERE depot_id = ? AND stock_id = ? FOR UPDATE");
        $stmtCheck->execute([$destinationId, $articleId]);
        $exists = $stmtCheck->fetchColumn();

        if ($exists !== false) {
            $stmtUpdate = $pdo->prepare("
                UPDATE stock_depots SET quantite = quantite + :qte
                WHERE depot_id = :dest AND stock_id = :article
            ");
            $stmtUpdate->execute(['qte' => $quantite, 'dest' => $destinationId, 'article' => $articleId]);
        } else {
            $stmtInsert = $pdo->prepare("
                INSERT INTO stock_depots (depot_id, stock_id, quantite) VALUES (:dest, :article, :qte)
            ");
            $stmtInsert->execute(['dest' => $destinationId, 'article' => $articleId, 'qte' => $quantite]);
        }
    } else { // destination = chantier
        $stmtCheck = $pdo->prepare("SELECT quantite FROM stock_chantiers WHERE chantier_id = ? AND stock_id = ? FOR UPDATE");
        $stmtCheck->execute([$destinationId, $articleId]);
        $exists = $stmtCheck->fetchColumn();

        if ($exists !== false) {
            $stmtUpdate = $pdo->prepare("
                UPDATE stock_chantiers SET quantite = quantite + :qte
                WHERE chantier_id = :dest AND stock_id = :article
            ");
            $stmtUpdate->execute(['qte' => $quantite, 'dest' => $destinationId, 'article' => $articleId]);
        } else {
            $stmtInsert = $pdo->prepare("
                INSERT INTO stock_chantiers (chantier_id, stock_id, quantite) VALUES (:dest, :article, :qte)
            ");
            $stmtInsert->execute(['dest' => $destinationId, 'article' => $articleId, 'qte' => $quantite]);
        }
    }

    // 🔻 Retirer de la source uniquement si source = chantier
    // (si source = dépôt, déjà décrémenté au moment de la demande)
    if ($sourceType === 'chantier') {
        $stmtUpdate = $pdo->prepare("
            UPDATE stock_chantiers
            SET quantite = GREATEST(quantite - :qte, 0)
            WHERE chantier_id = :src AND stock_id = :article
        ");
        $stmtUpdate->execute(['qte' => $quantite, 'src' => $sourceId, 'article' => $articleId]);
    }

    // 🧾 Historique
    $commentaire = $transfert['commentaire'] ?? null;
    $stmtMv = $pdo->prepare("
        INSERT INTO stock_mouvements
            (stock_id, type, source_type, source_id, dest_type, dest_id, quantite, statut, commentaire, utilisateur_id, demandeur_id, created_at)
        VALUES
            (:stock_id, 'transfert', :src_type, :src_id, :dest_type, :dest_id, :qte, 'valide', :commentaire, :validateur_id, :demandeur_id, NOW())
    ");
    $stmtMv->execute([
        ':stock_id'      => $articleId,
        ':src_type'      => $sourceType,
        ':src_id'        => $sourceId,
        ':dest_type'     => $destinationType,
        ':dest_id'       => $destinationId,
        ':qte'           => $quantite,
        ':commentaire'   => $commentaire,
        ':validateur_id' => $validatorUserId,
        ':demandeur_id'  => $demandeurId,
    ]);

    // 🗑️ Supprimer l'enregistrement en attente
    $stmtDelete = $pdo->prepare("DELETE FROM transferts_en_attente WHERE id = ?");
    $stmtDelete->execute([$transfertId]);

    // 🔔 Notification au demandeur
    $message = "✅ Le transfert de {$quantite} x {$transfert['article_nom']} a été validé par l'administrateur.";
    $stmtNotif = $pdo->prepare("INSERT INTO notifications (utilisateur_id, message) VALUES (?, ?)");
    $stmtNotif->execute([$demandeurId, $message]);

    $pdo->commit();

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        echo json_encode(['success' => true, 'transfert_id' => $transfertId]);
        exit;
    }

    $_SESSION['success_message'] = "Transfert validé avec succès.";
    $_SESSION['highlight_stock_id'] = $articleId;
    header("Location: stock_admin.php");
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        echo json_encode(['success' => false, 'message' => "Erreur : " . $e->getMessage()]);
        exit;
    }

    $_SESSION['error_message'] = "Erreur lors de la validation : " . $e->getMessage();
    header("Location: stock_admin.php");
    exit;
}
