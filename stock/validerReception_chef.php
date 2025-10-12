<?php
// Fichier: /stock/validerReception_chef.php (corrigé + alerte compteur)
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';

if (empty($_SESSION['utilisateurs'])) {
    header("Location: ../connexion.php");
    exit;
}

$user   = $_SESSION['utilisateurs'];
$chefId = (int)($user['id'] ?? 0);
$role   = (string)($user['fonction'] ?? '');
$ENT_ID = (int)($user['entreprise_id'] ?? 0);

if ($role !== 'chef' && $role !== 'administrateur') {
    $_SESSION['error_message'] = "Accès refusé.";
    header("Location: stock_chef.php");
    exit;
}

/* Chantiers rattachés au chef (pour contrôle) */
$stmtChefChantiers = $pdo->prepare("
    SELECT chantier_id
    FROM utilisateur_chantiers
    WHERE utilisateur_id = ?
");
$stmtChefChantiers->execute([$chefId]);
$chantierIds = array_map('intval', $stmtChefChantiers->fetchAll(PDO::FETCH_COLUMN));

if ($role === 'chef' && empty($chantierIds)) {
    $_SESSION['error_message'] = "Aucun chantier associé à ce chef.";
    header("Location: stock_chef.php");
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['transfert_id'])) {
    $transfertId = (int)$_POST['transfert_id'];

    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->beginTransaction();

        // 1) Récupérer + verrouiller la demande (bornée par entreprise via stock)
        $sql = "
            SELECT t.*, s.nom AS article_nom, s.entreprise_id AS ent_article,
                   d.entreprise_id AS ent_depot_src,
                   c_src.entreprise_id AS ent_ch_src,
                   c_dst.entreprise_id AS ent_ch_dst
            FROM transferts_en_attente t
            JOIN stock s              ON s.id = t.article_id AND s.entreprise_id = :ent
            LEFT JOIN depots d        ON (t.source_type='depot'    AND d.id     = t.source_id)
            LEFT JOIN chantiers c_src ON (t.source_type='chantier' AND c_src.id = t.source_id)
            LEFT JOIN chantiers c_dst ON (t.destination_type='chantier' AND c_dst.id = t.destination_id)
            WHERE t.id = :tid
              AND t.statut = 'en_attente'
              AND t.destination_type = 'chantier'
            FOR UPDATE
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':tid' => $transfertId, ':ent' => $ENT_ID]);
        $t = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$t) {
            throw new RuntimeException("Transfert introuvable ou déjà traité.");
        }

        $articleId   = (int)$t['article_id'];   // stock.id
        $quantite    = (int)$t['quantite'];
        $destChId    = (int)$t['destination_id'];
        $demandeurId = (int)$t['demandeur_id'];
        $srcType     = (string)$t['source_type'];     // 'depot' | 'chantier'
        $srcId       = isset($t['source_id']) ? (int)$t['source_id'] : 0;
        $articleNom  = (string)$t['article_nom'];

        // 2) Cohérence multi-entreprise
        if ($ENT_ID <= 0 || (int)$t['ent_article'] !== $ENT_ID) {
            throw new RuntimeException("Article non autorisé pour cette entreprise.");
        }
        if ($srcType === 'depot') {
            if ((int)$t['ent_depot_src'] !== $ENT_ID) {
                throw new RuntimeException("Dépôt source non autorisé.");
            }
        } else {
            if ((int)$t['ent_ch_src'] !== $ENT_ID) {
                throw new RuntimeException("Chantier source non autorisé.");
            }
        }
        if ((int)$t['ent_ch_dst'] !== $ENT_ID) {
            throw new RuntimeException("Chantier destination non autorisé.");
        }

        // 3) Le chef doit être rattaché au chantier destination (sauf admin)
        if ($role === 'chef' && !in_array($destChId, $chantierIds, true)) {
            throw new RuntimeException("Vous ne pouvez valider que sur vos chantiers.");
        }

        // 4) Décrémenter la source si source = chantier (bornage entreprise)
        if ($srcType === 'chantier' && $srcId) {
            $lockSrc = $pdo->prepare("
                SELECT sc.quantite
                FROM stock_chantiers sc
                JOIN chantiers c ON c.id = sc.chantier_id AND c.entreprise_id = :ent
                WHERE sc.chantier_id = :cid AND sc.stock_id = :sid
                FOR UPDATE
            ");
            $lockSrc->execute([':cid' => $srcId, ':sid' => $articleId, ':ent' => $ENT_ID]);
            $qSrc = (int)($lockSrc->fetchColumn() ?: 0);

            $newSrc = max(0, $qSrc - $quantite);

            $updSrc = $pdo->prepare("
                UPDATE stock_chantiers sc
                JOIN chantiers c ON c.id = sc.chantier_id AND c.entreprise_id = :ent
                   SET sc.quantite = :q
                 WHERE sc.chantier_id = :cid AND sc.stock_id = :sid
            ");
            $updSrc->execute([
                ':q'   => $newSrc,
                ':cid' => $srcId,
                ':sid' => $articleId,
                ':ent' => $ENT_ID
            ]);
        }
        // (si source = dépôt : décrément déjà fait à l’envoi, sinon gère-le ici de la même manière)

        // 5) Incrémenter la destination (UPSERT) — AVEC entreprise_id !!
        $lockDst = $pdo->prepare("
            SELECT sc.quantite
            FROM stock_chantiers sc
            JOIN chantiers c ON c.id = sc.chantier_id AND c.entreprise_id = :ent
            WHERE sc.chantier_id = :cid AND sc.stock_id = :sid
            FOR UPDATE
        ");
        $lockDst->execute([':cid' => $destChId, ':sid' => $articleId, ':ent' => $ENT_ID]);
        $existsDst = $lockDst->fetch(PDO::FETCH_ASSOC);

        if ($existsDst !== false) {
            $updDst = $pdo->prepare("
                UPDATE stock_chantiers sc
                JOIN chantiers c ON c.id = sc.chantier_id AND c.entreprise_id = :ent
                   SET sc.quantite = sc.quantite + :q
                 WHERE sc.chantier_id = :cid AND sc.stock_id = :sid
            ");
            $updDst->execute([
                ':q'   => $quantite,
                ':cid' => $destChId,
                ':sid' => $articleId,
                ':ent' => $ENT_ID
            ]);
        } else {
            $insDst = $pdo->prepare("
                INSERT INTO stock_chantiers (entreprise_id, chantier_id, stock_id, quantite)
                VALUES (:ent, :cid, :sid, :q)
            ");
            $insDst->execute([
                ':ent' => $ENT_ID,
                ':cid' => $destChId,
                ':sid' => $articleId,
                ':q'   => $quantite
            ]);
        }

        /* 5.1) Alerte relevé d'heures si machine à compteur */
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
                // éviter les doublons
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

                    // Notifier les chefs rattachés au chantier destinataire (avec stock_id)
                    try {
                        $stmtChefs = $pdo->prepare("
                            SELECT u.id
                            FROM utilisateurs u
                            JOIN utilisateur_chantiers uc ON uc.utilisateur_id = u.id
                            WHERE uc.chantier_id = :cid
                              AND u.fonction = 'chef'
                              AND u.entreprise_id = :eid
                        ");
                        $stmtChefs->execute([':cid' => $destChId, ':eid' => $ENT_ID]);
                        $chefIds = $stmtChefs->fetchAll(PDO::FETCH_COLUMN);

                        if (!empty($chefIds)) {
                            $notifMsg = "⏱️ Relevé d'heures demandé pour {$articleNom}. Flashez le QR et saisissez le compteur.";
                            foreach ($chefIds as $cid) {
                                try {
                                    $pdo->prepare("
                                        INSERT INTO notifications (utilisateur_id, message, entreprise_id, stock_id, created_at)
                                        VALUES (:uid, :msg, :eid, :sid, NOW())
                                    ")->execute([
                                        ':uid' => (int)$cid,
                                        ':msg' => $notifMsg,
                                        ':eid' => $ENT_ID,
                                        ':sid' => $articleId,
                                    ]);
                                } catch (Throwable $e) {
                                    // fallback si la colonne stock_id n'existe pas
                                    $pdo->prepare("
                                        INSERT INTO notifications (utilisateur_id, message, entreprise_id, created_at)
                                        VALUES (:uid, :msg, :eid, NOW())
                                    ")->execute([
                                        ':uid' => (int)$cid,
                                        ':msg' => $notifMsg,
                                        ':eid' => $ENT_ID,
                                    ]);
                                }
                            }
                        }
                    } catch (Throwable $e) {
                        error_log('notif chefs hour_meter_request (chef): ' . $e->getMessage());
                    }

                    // Notifier aussi le dépôt source (si l'envoi vient d'un dépôt) avec stock_id
                    if ($srcType === 'depot' && $srcId) {
                        try {
                            $q = $pdo->prepare("
                                SELECT responsable_id
                                FROM depots
                                WHERE id = :id AND entreprise_id = :eid
                            ");
                            $q->execute([':id' => $srcId, ':eid' => $ENT_ID]);
                            $respoId = (int)$q->fetchColumn();

                            if ($respoId > 0) {
                                $notifMsgDepot = "⏱️ Relevé d'heures demandé pour {$articleNom} (envoyé depuis votre dépôt).";
                                try {
                                    $pdo->prepare("
                                        INSERT INTO notifications (utilisateur_id, message, stock_id, entreprise_id, is_read, created_at)
                                        VALUES (:uid, :msg, :sid, :eid, 0, NOW())
                                    ")->execute([
                                        ':uid' => $respoId,
                                        ':msg' => $notifMsgDepot,
                                        ':sid' => $articleId,   // important pour le bouton Ouvrir
                                        ':eid' => $ENT_ID,
                                    ]);
                                } catch (Throwable $e) {
                                    // fallback
                                    $pdo->prepare("
                                        INSERT INTO notifications (utilisateur_id, message, entreprise_id, created_at)
                                        VALUES (:uid, :msg, :eid, NOW())
                                    ")->execute([
                                        ':uid' => $respoId,
                                        ':msg' => $notifMsgDepot,
                                        ':eid' => $ENT_ID,
                                    ]);
                                }
                            }
                        } catch (Throwable $e) {
                            error_log('notif depot hour_meter_request: ' . $e->getMessage());
                        }
                    }
                } // <-- fin if (!$already)
            } // <-- fin if ($isHourMeter)
        } catch (Throwable $e) {
            // on log, sans bloquer la réception
            error_log('hour_meter_request alert failed: ' . $e->getMessage());
        }
        /* [/NOUVEAU] */

        // 6) Historique
        $commentaire = $t['commentaire'] ?? null;
        $stmtMv = $pdo->prepare("
            INSERT INTO stock_mouvements
              (stock_id, type, source_type, source_id, dest_type, dest_id,
               quantite, statut, commentaire, utilisateur_id, demandeur_id, entreprise_id, created_at)
            VALUES
              (:stock_id, 'transfert', :src_type, :src_id, 'chantier', :dest_id,
               :qte, 'valide', :commentaire, :validateur_id, :demandeur_id, :ent_id, NOW())
        ");
        $stmtMv->execute([
            ':stock_id'      => $articleId,
            ':src_type'      => $srcType,
            ':src_id'        => $srcId,
            ':dest_id'       => $destChId,
            ':qte'           => $quantite,
            ':commentaire'   => $commentaire,
            ':validateur_id' => $chefId,
            ':demandeur_id'  => $demandeurId,
            ':ent_id'        => $ENT_ID,
        ]);

        // 7) Nettoyage de la demande en attente
        $stmtDelete = $pdo->prepare("DELETE FROM transferts_en_attente WHERE id = ?");
        $stmtDelete->execute([$transfertId]);

        $pdo->commit();

        $_SESSION['success_message']    = "Transfert validé avec succès.";
        $_SESSION['highlight_stock_id'] = $articleId;

        header("Location: stock_chef.php?chantier_id={$destChId}");
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error_message'] = "Erreur lors de la validation : " . $e->getMessage();
        header("Location: stock_chef.php");
        exit;
    }
}

// GET direct
header("Location: stock_chef.php");
exit;
