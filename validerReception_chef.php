<?php
require_once "./config/init.php";

if (!isset($_SESSION['utilisateurs'])) {
    header("Location: connexion.php");
    exit;
}

$chefId = $_SESSION['utilisateurs']['id'];

// Chantiers du chef
$stmtChefChantiers = $pdo->prepare("
    SELECT chantier_id 
    FROM utilisateur_chantiers 
    WHERE utilisateur_id = ?
");
$stmtChefChantiers->execute([$chefId]);
$chantierIds = $stmtChefChantiers->fetchAll(PDO::FETCH_COLUMN);

if (empty($chantierIds)) {
    $_SESSION['error_message'] = "Aucun chantier associé à ce chef.";
    header("Location: stock_chef.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfert_id'])) {
    $transfertId = (int)$_POST['transfert_id'];
    $placeholders = implode(',', array_fill(0, count($chantierIds), '?'));

    try {
        // ⚠️ Démarrer la transaction AVANT le SELECT ... FOR UPDATE
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT t.*, s.nom AS article_nom
            FROM transferts_en_attente t
            JOIN stock s ON t.article_id = s.id
            WHERE t.id = ? 
              AND t.destination_type = 'chantier' 
              AND t.destination_id IN ($placeholders)
              AND t.statut = 'en_attente'
            FOR UPDATE
        ");
        $stmt->execute(array_merge([$transfertId], $chantierIds));
        $transfert = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$transfert) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Transfert introuvable ou déjà validé.";
            header("Location: stock_chef.php");
            exit;
        }

        $articleId       = (int)$transfert['article_id'];
        $quantite        = (int)$transfert['quantite'];
        $chantierId      = (int)$transfert['destination_id'];
        $demandeurId     = (int)$transfert['demandeur_id'];
        $articleNom      = htmlspecialchars($transfert['article_nom']);
        $sourceType      = $transfert['source_type'];                // 'depot' | 'chantier'
        $sourceId        = isset($transfert['source_id']) ? (int)$transfert['source_id'] : null;

        // 🔻 Si source = chantier, décrémenter la source
        if ($sourceType === 'chantier') {
            $stmt = $pdo->prepare("
                UPDATE stock_chantiers
                SET quantite = GREATEST(quantite - :qte, 0)
                WHERE chantier_id = :source AND stock_id = :article
            ");
            $stmt->execute([
                'qte'     => $quantite,
                'source'  => $sourceId,
                'article' => $articleId
            ]);
        }
        // (si source = dépôt, déjà décrémenté à l'envoi)

        // ➕ Incrémenter le chantier destination
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM stock_chantiers WHERE chantier_id = ? AND stock_id = ?");
        $stmtCheck->execute([$chantierId, $articleId]);
        $exists = (bool)$stmtCheck->fetchColumn();

        if ($exists) {
            $stmtUpdate = $pdo->prepare("
                UPDATE stock_chantiers 
                SET quantite = quantite + :qte 
                WHERE chantier_id = :chantier AND stock_id = :article
            ");
            $stmtUpdate->execute([
                'qte'      => $quantite,
                'chantier' => $chantierId,
                'article'  => $articleId
            ]);
        } else {
            $stmtInsert = $pdo->prepare("
                INSERT INTO stock_chantiers (chantier_id, stock_id, quantite) 
                VALUES (:chantier, :article, :qte)
            ");
            $stmtInsert->execute([
                'chantier' => $chantierId,
                'article'  => $articleId,
                'qte'      => $quantite
            ]);
        }

        // 🧾 Historique du mouvement (validation par le chef)
        $stmtMv = $pdo->prepare("
            INSERT INTO stock_mouvements
                (stock_id, type, source_type, source_id, dest_type, dest_id, quantite, statut, utilisateur_id, created_at)
            VALUES
                (:stock_id, 'transfert', :src_type, :src_id, 'chantier', :dest_id, :qte, 'valide', :user_id, NOW())
        ");
        $stmtMv->execute([
            ':stock_id' => $articleId,
            ':src_type' => $sourceType,
            ':src_id'   => ($sourceType === 'chantier') ? $sourceId : null, // null si dépôt
            ':dest_id'  => $chantierId,
            ':qte'      => $quantite,
            ':user_id'  => $chefId,
        ]);

        // ✅ Supprimer le transfert en attente
        $stmtDelete = $pdo->prepare("DELETE FROM transferts_en_attente WHERE id = ?");
        $stmtDelete->execute([$transfertId]);

        // 🔔 Notifier le demandeur
        $message = "✅ Le transfert de {$quantite} x {$articleNom} a été validé par le chantier.";
        $stmtNotif = $pdo->prepare("INSERT INTO notifications (utilisateur_id, message) VALUES (?, ?)");
        $stmtNotif->execute([$demandeurId, $message]);

        $pdo->commit();

        $_SESSION['success_message'] = "Transfert validé avec succès.";
        $_SESSION['highlight_stock_id'] = $articleId;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error_message'] = "Erreur lors de la validation : " . $e->getMessage();
    }

    header("Location: stock_chef.php?chantier_id=" . ($chantierId ?? ''));
    exit;
}

// GET direct
header("Location: stock_chef.php");
exit;
