<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';

// ---------- Accès ----------
if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? null) !== 'administrateur') {
    $_SESSION['error_message'] = "Accès refusé.";
    header("Location: stock_admin.php");
    exit;
}

// ---------- Multi-entreprise ----------
$ENT_ID = $_SESSION['utilisateurs']['entreprise_id'] ?? null;
$ENT_ID = is_numeric($ENT_ID) ? (int)$ENT_ID : null;

function me_where_first(?int $ENT_ID, string $alias = ''): array
{
    if ($ENT_ID === null) return ['', []];
    $pfx = $alias ? $alias . '.' : '';
    return [" WHERE {$pfx}entreprise_id = :eid ", [':eid' => $ENT_ID]];
}
function me_where(?int $ENT_ID, string $alias = ''): array
{
    if ($ENT_ID === null) return ['', []];
    $pfx = $alias ? $alias . '.' : '';
    return [" AND {$pfx}entreprise_id = :eid ", [':eid' => $ENT_ID]];
}
function belongs_or_fallback(PDO $pdo, string $table, int $id, ?int $ENT_ID): bool
{
    if ($ENT_ID === null) return true;
    try {
        $st = $pdo->prepare("SELECT 1 FROM {$table} t WHERE t.id = :id AND t.entreprise_id = :eid");
        $st->execute([':id' => $id, ':eid' => $ENT_ID]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return true;
    }
}

// ---------- Vérifs requête ----------
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

// ---------- Charger le transfert en attente ----------
try {
    $sql = "
        SELECT t.*, s.nom AS article_nom
        FROM transferts_en_attente t
        JOIN stock s ON t.article_id = s.id
        WHERE t.id = :tid AND t.statut = 'en_attente'
    ";
    $params = [':tid' => $transfertId];

    if ($ENT_ID !== null) {
        try {
            $sql .= " AND t.entreprise_id = :eid";
            $params[':eid'] = $ENT_ID;
        } catch (Throwable $e) { /* ignoré */
        }
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transfert = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $transfert = false;
}

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

// ---------- Garde-fous multi-entreprise ----------
if ($ENT_ID !== null) {
    if (!belongs_or_fallback($pdo, 'stock', $articleId, $ENT_ID)) {
        $_SESSION['error_message'] = "Article hors de votre entreprise.";
        header("Location: stock_admin.php");
        exit;
    }
    if ($sourceType === 'depot' && $sourceId && !belongs_or_fallback($pdo, 'depots', $sourceId, $ENT_ID)) {
        $_SESSION['error_message'] = "Source (dépôt) hors de votre entreprise.";
        header("Location: stock_admin.php");
        exit;
    }
    if ($sourceType === 'chantier' && $sourceId && !belongs_or_fallback($pdo, 'chantiers', $sourceId, $ENT_ID)) {
        $_SESSION['error_message'] = "Source (chantier) hors de votre entreprise.";
        header("Location: stock_admin.php");
        exit;
    }
    if ($destinationType === 'depot' && $destinationId && !belongs_or_fallback($pdo, 'depots', $destinationId, $ENT_ID)) {
        $_SESSION['error_message'] = "Destination (dépôt) hors de votre entreprise.";
        header("Location: stock_admin.php");
        exit;
    }
    if ($destinationType === 'chantier' && $destinationId && !belongs_or_fallback($pdo, 'chantiers', $destinationId, $ENT_ID)) {
        $_SESSION['error_message'] = "Destination (chantier) hors de votre entreprise.";
        header("Location: stock_admin.php");
        exit;
    }
}

try {
    $pdo->beginTransaction();

    // === Ajout côté destination ===
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
            $insertDone = false;
            if ($ENT_ID !== null) {
                try {
                    $pdo->prepare("
                        INSERT INTO stock_depots (depot_id, stock_id, quantite, entreprise_id)
                        VALUES (:dest, :article, :qte, :eid)
                    ")->execute(['dest' => $destinationId, 'article' => $articleId, 'qte' => $quantite, 'eid' => $ENT_ID]);
                    $insertDone = true;
                } catch (Throwable $e) { /* fallback */
                }
            }
            if (!$insertDone) {
                $stmtInsert = $pdo->prepare("
                    INSERT INTO stock_depots (depot_id, stock_id, quantite) VALUES (:dest, :article, :qte)
                ");
                $stmtInsert->execute(['dest' => $destinationId, 'article' => $articleId, 'qte' => $quantite]);
            }
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
            $insertDone = false;
            if ($ENT_ID !== null) {
                try {
                    $pdo->prepare("
                        INSERT INTO stock_chantiers (chantier_id, stock_id, quantite, entreprise_id)
                        VALUES (:dest, :article, :qte, :eid)
                    ")->execute(['dest' => $destinationId, 'article' => $articleId, 'qte' => $quantite, 'eid' => $ENT_ID]);
                    $insertDone = true;
                } catch (Throwable $e) { /* fallback */
                }
            }
            if (!$insertDone) {
                $stmtInsert = $pdo->prepare("
                    INSERT INTO stock_chantiers (chantier_id, stock_id, quantite) VALUES (:dest, :article, :qte)
                ");
                $stmtInsert->execute(['dest' => $destinationId, 'article' => $articleId, 'qte' => $quantite]);
            }
        }

        /* 5.1) [NOUVEAU] Alerte relevé d'heures si machine à compteur */
        try {
            $stHM = $pdo->prepare("
        SELECT maintenance_mode,
               COALESCE(profil_qr, '') AS profil_qr
        FROM stock
        WHERE id = ?
        LIMIT 1
    ");
            $stHM->execute([$articleId]);
            $s = $stHM->fetch(PDO::FETCH_ASSOC);

            $isHourMeter = $s && (
                ($s['maintenance_mode'] ?? '') === 'hour_meter'
                || ($s['profil_qr'] ?? '') === 'compteur_heures'
            );

            if ($isHourMeter) {
                $chk = $pdo->prepare("
            SELECT id FROM stock_alerts
            WHERE stock_id = ? AND type = 'hour_meter_request' AND archived_at IS NULL
            LIMIT 1
        ");
                $chk->execute([$articleId]);
                $already = $chk->fetchColumn();

                if (!$already) {
                    $msg = "Machine reçue sur chantier : merci de flasher le QR et de saisir le compteur d'heures.";
                    $ins = $pdo->prepare("
                INSERT INTO stock_alerts (stock_id, message, type, is_read, created_at)
                VALUES (?, ?, 'hour_meter_request', 0, NOW())
            ");
                    $ins->execute([$articleId, $msg]);

                    // Notifier les chefs du chantier destinataire
                    try {
                        $stmtChefs = $pdo->prepare("
                    SELECT u.id
                    FROM utilisateurs u
                    JOIN utilisateur_chantiers uc ON uc.utilisateur_id = u.id
                    WHERE uc.chantier_id = :cid
                      AND u.fonction = 'chef'
                      AND u.entreprise_id = :eid
                ");
                        $stmtChefs->execute([':cid' => $destinationId, ':eid' => $ENT_ID]);
                        $chefIds = $stmtChefs->fetchAll(PDO::FETCH_COLUMN);

                        if (!empty($chefIds)) {
                            $notifMsg = "⏱️ Relevé d'heures demandé pour {$transfert['article_nom']}. Flashez le QR et saisissez le compteur.";
                            $insNotif = $pdo->prepare("
                        INSERT INTO notifications (utilisateur_id, message, entreprise_id, created_at)
                        VALUES (:uid, :msg, :eid, NOW())
                    ");
                            foreach ($chefIds as $cid) {
                                $insNotif->execute([
                                    ':uid' => (int)$cid,
                                    ':msg' => $notifMsg,
                                    ':eid' => $ENT_ID,
                                ]);
                            }
                        }
                    } catch (Throwable $e) {
                        error_log('notif chefs hour_meter_request (admin): ' . $e->getMessage());
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('hour_meter_request alert failed (admin): ' . $e->getMessage());
        }

        /* [/NOUVEAU] */
    }

    // === Retrait côté source (uniquement si source = chantier)
    if ($sourceType === 'chantier') {
        $stmtUpdate = $pdo->prepare("
            UPDATE stock_chantiers
            SET quantite = GREATEST(quantite - :qte, 0)
            WHERE chantier_id = :src AND stock_id = :article
        ");
        $stmtUpdate->execute(['qte' => $quantite, 'src' => $sourceId, 'article' => $articleId]);
    }

    // === Historique ===
    $commentaire = $transfert['commentaire'] ?? null;
    $stmtMv = $pdo->prepare("
        INSERT INTO stock_mouvements
            (stock_id, type, source_type, source_id, dest_type, dest_id, quantite, statut, commentaire, utilisateur_id, demandeur_id, created_at
            " . ($ENT_ID !== null ? ", entreprise_id" : "") . ")
        VALUES
            (:stock_id, 'transfert', :src_type, :src_id, :dest_type, :dest_id, :qte, 'valide', :commentaire, :validateur_id, :demandeur_id, NOW()
            " . ($ENT_ID !== null ? ", :eid" : "") . ")
    ");
    $paramsMv = [
        ':stock_id'      => $articleId,
        ':src_type'      => $sourceType,
        ':src_id'        => $sourceId,
        ':dest_type'     => $destinationType,
        ':dest_id'       => $destinationId,
        ':qte'           => $quantite,
        ':commentaire'   => $commentaire,
        ':validateur_id' => $validatorUserId,
        ':demandeur_id'  => $demandeurId,
    ];
    if ($ENT_ID !== null) $paramsMv[':eid'] = $ENT_ID;
    $stmtMv->execute($paramsMv);

    // === Supprimer la demande en attente ===
    $stmtDelete = $pdo->prepare("DELETE FROM transferts_en_attente WHERE id = ?");
    $stmtDelete->execute([$transfertId]);

    // === Notification au demandeur ===
    $message = "✅ Le transfert de {$quantite} x {$transfert['article_nom']} a été validé par l'administrateur.";
    if ($ENT_ID !== null) {
        try {
            $pdo->prepare("INSERT INTO notifications (utilisateur_id, message, entreprise_id) VALUES (?, ?, ?)")
                ->execute([$demandeurId, $message, $ENT_ID]);
        } catch (Throwable $e) {
            $pdo->prepare("INSERT INTO notifications (utilisateur_id, message) VALUES (?, ?)")
                ->execute([$demandeurId, $message]);
        }
    } else {
        $pdo->prepare("INSERT INTO notifications (utilisateur_id, message) VALUES (?, ?)")
            ->execute([$demandeurId, $message]);
    }

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
