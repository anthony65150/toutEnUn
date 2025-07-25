<?php
require_once "./config/init.php";

if (!isset($_SESSION['utilisateurs'])) {
    header("Location: connexion.php");
    exit;
}

$chefId = $_SESSION['utilisateurs']['id'];

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

    $stmt = $pdo->prepare("
        SELECT t.*, s.nom AS article_nom
        FROM transferts_en_attente t
        JOIN stock s ON t.article_id = s.id
        WHERE t.id = ? AND t.destination_type = 'chantier' 
        AND t.destination_id IN ($placeholders)
        AND t.statut = 'en_attente'
        FOR UPDATE
    ");
    $stmt->execute(array_merge([$transfertId], $chantierIds));
    $transfert = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($transfert) {
        $articleId = $transfert['article_id'];
        $quantite = (int)$transfert['quantite'];
        $chantierId = $transfert['destination_id'];
        $demandeurId = $transfert['demandeur_id'];
        $articleNom = htmlspecialchars($transfert['article_nom']);

        try {
            $pdo->beginTransaction();

            if ($transfert['source_type'] === 'chantier') {
                $stmt = $pdo->prepare("
                    UPDATE stock_chantiers
                    SET quantite = GREATEST(quantite - :qte, 0)
                    WHERE chantier_id = :source AND stock_id = :article
                ");
                $stmt->execute([
                    'qte' => $quantite,
                    'source' => $transfert['source_id'],
                    'article' => $articleId
                ]);
            }

            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM stock_chantiers WHERE chantier_id = ? AND stock_id = ?");
            $stmtCheck->execute([$chantierId, $articleId]);
            $exists = $stmtCheck->fetchColumn();

            if ($exists) {
                $stmtUpdate = $pdo->prepare("
                    UPDATE stock_chantiers 
                    SET quantite = quantite + :qte 
                    WHERE chantier_id = :chantier AND stock_id = :article
                ");
                $stmtUpdate->execute([
                    'qte' => $quantite,
                    'chantier' => $chantierId,
                    'article' => $articleId
                ]);
            } else {
                $stmtInsert = $pdo->prepare("
                    INSERT INTO stock_chantiers (chantier_id, stock_id, quantite) 
                    VALUES (:chantier, :article, :qte)
                ");
                $stmtInsert->execute([
                    'chantier' => $chantierId,
                    'article' => $articleId,
                    'qte' => $quantite
                ]);
            }

            $stmtDelete = $pdo->prepare("DELETE FROM transferts_en_attente WHERE id = ?");
            $stmtDelete->execute([$transfertId]);

            $message = "✅ Le transfert de {$quantite} x {$articleNom} a été validé par le chantier.";
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
        $_SESSION['error_message'] = "Transfert introuvable ou déjà validé.";
    }
}

header("Location: stock_chef.php?chantier_id=" . ($chantierId ?? ''));
exit;
