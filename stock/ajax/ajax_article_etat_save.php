<?php

declare(strict_types=1);

// /stock/ajax/ajax_article_etat_save.php
require_once __DIR__ . '/../../config/init.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// --- Trappe erreurs fatales -> renvoyer du JSON propre ---
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        http_response_code(500);
        echo json_encode([
            'ok'  => false,
            'msg' => 'Fatal: ' . $e['message'] . ' @ ' . basename($e['file']) . ':' . $e['line'],
        ], JSON_UNESCAPED_UNICODE);
    }
});

function jexit(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/* -------------------------------------------------------
   Helpers
------------------------------------------------------- */
function recent_alert_exists_role(PDO $pdo, int $stockId, string $url, string $msg, ?string $role): ?int
{
    $sql = "
        SELECT id
        FROM stock_alerts
        WHERE stock_id = :sid
          AND type = 'incident'
          AND url  = :url
          AND message = :msg
          AND archived_at IS NULL
          AND (is_read = 0 OR is_read IS NULL)
          AND created_at >= (NOW() - INTERVAL 2 MINUTE)
    ";
    $params = [':sid' => $stockId, ':url' => $url, ':msg' => $msg];
    if ($role !== null) {
        $sql .= " AND target_role = :role";
        $params[':role'] = $role;
    }
    $sql .= " ORDER BY id DESC LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

function article_by_id(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("
        SELECT id, entreprise_id, maintenance_mode,
               compteur_heures,
               COALESCE(hour_meter_initial, 0)      AS hour_meter_initial,
               COALESCE(maintenance_threshold, 100) AS maintenance_threshold
        FROM stock
        WHERE id = :id
        LIMIT 1
    ");
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function maintenance_alert_exists(PDO $pdo, int $stockId, ?int $entId = null): bool
{
    $sql = "
        SELECT 1
        FROM stock_alerts
        WHERE stock_id = :sid
          AND type = 'incident'
          AND url = 'maintenance_due'
          AND is_read = 0
          AND archived_at IS NULL
    ";
    $params = [':sid' => $stockId];

    if ($entId) {
        $sql .= " AND entreprise_id = :eid";
        $params[':eid'] = $entId;
    }

    $q = $pdo->prepare($sql . " LIMIT 1");
    $q->execute($params);
    return (bool)$q->fetchColumn();
}

function create_maintenance_alert(PDO $pdo, int $stockId, int $entrepriseId, int $current, int $limit): void
{
    if (maintenance_alert_exists($pdo, $stockId, $entrepriseId)) return;
    $msg = "Entretien à prévoir : compteur {$current} h (≥ {$limit} h).";
    $ins = $pdo->prepare("
        INSERT INTO stock_alerts (entreprise_id, stock_id, type, message, url, created_at, is_read)
        VALUES (:eid, :sid, 'incident', :msg, 'maintenance_due', NOW(), 0)
    ");
    $ins->execute([':eid' => $entrepriseId, ':sid' => $stockId, ':msg' => $msg]);
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/** Idempotence : existe-t-il déjà une alerte "problem" identique créée très récemment ? */
function recent_problem_exists(PDO $pdo, int $stockId, string $msg): ?int
{
    $q = $pdo->prepare("
        SELECT id
        FROM stock_alerts
        WHERE stock_id = :sid
          AND type = 'incident'
          AND url  = 'problem'
          AND archived_at IS NULL
          AND is_read = 0
          AND message = :msg
          AND created_at >= (NOW() - INTERVAL 2 MINUTE)
        ORDER BY id DESC
        LIMIT 1
    ");
    $q->execute([':sid' => $stockId, ':msg' => $msg]);
    $id = $q->fetchColumn();
    return $id ? (int)$id : null;
}

/** Déduit le chantier courant depuis les affectations (stock_chantiers) */
function current_chantier_id(PDO $pdo, int $stockId, int $entId = 0): ?int
{
    $sql = "
        SELECT sc.chantier_id
        FROM stock_chantiers sc
        " . ($entId ? "JOIN chantiers c ON c.id = sc.chantier_id AND c.entreprise_id = :eid" : "") . "
        WHERE sc.stock_id = :sid
          AND sc.quantite > 0
        ORDER BY sc.created_at DESC, sc.id DESC
        LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $params = [':sid' => $stockId];
    if ($entId) $params[':eid'] = $entId;
    $st->execute($params);
    $cid = $st->fetchColumn();
    return $cid ? (int)$cid : null;
}

/* -------------------------------------------------------
   Paramètres bruts
------------------------------------------------------- */
$actionRaw   = (string)($_POST['action'] ?? '');
$articleId   = (int)($_POST['article_id'] ?? ($_POST['stock_id'] ?? 0));
$valeurInt   = array_key_exists('valeur_int', $_POST) ? (int)$_POST['valeur_int'] : null;
$hours       = array_key_exists('hours', $_POST)      ? (int)$_POST['hours']      : null;
$chantierId  = array_key_exists('chantier_id', $_POST) ? (int)$_POST['chantier_id'] : null;
$comment     = trim((string)($_POST['commentaire'] ?? ($_POST['message'] ?? '')));

$actionMap = [
    'hour_meter'      => 'compteur_maj',
    'declare_problem' => 'declarer_panne',
    'resolve_problem' => 'declarer_ok',
    'resolve_one'     => 'resolve_one',
];
$action = $actionMap[$actionRaw] ?? $actionRaw;

/* -------------------------------------------------------
   Auth / contexte (AVANT toute déduction de chantier_id)
------------------------------------------------------- */
$isLogged = isset($_SESSION['utilisateurs']);
$uid      = $isLogged ? (int)($_SESSION['utilisateurs']['id'] ?? 0) : 0;
$entId    = $isLogged ? (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0) : 0;
$role     = $isLogged ? (string)($_SESSION['utilisateurs']['fonction'] ?? '') : '';

$allowPublicQR = in_array($action, ['declarer_panne', 'compteur_maj', 'declarer_ok'], true);
if (!$isLogged && !$allowPublicQR) {
    jexit(401, ['ok' => false, 'msg' => 'Authentification requise']);
}

if ($articleId <= 0) jexit(400, ['ok' => false, 'msg' => 'article_id manquant']);

$art = article_by_id($pdo, $articleId);
if (!$art) jexit(404, ['ok' => false, 'msg' => 'Article introuvable']);
if ($isLogged && $entId > 0 && (int)$art['entreprise_id'] !== $entId) {
    jexit(403, ['ok' => false, 'msg' => "Article hors de votre entreprise"]);
}

$maintenanceMode = (string)($art['maintenance_mode'] ?? 'none');
$profil = $maintenanceMode === 'hour_meter' ? 'compteur_heures'
    : ($maintenanceMode === 'electrical' ? 'autre' : 'aucun');

/* -------------------------------------------------------
   Déduction du chantier_id (APRES $entId)
------------------------------------------------------- */
// 1) si le POST n'a pas donné chantier_id, on essaie l'affectation active
if (!$chantierId) {
    $chantierId = current_chantier_id($pdo, $articleId, $entId);
}

// 2) sinon, on tente le dernier mouvement impliquant un chantier
if (!$chantierId) {
    $qCid = $pdo->prepare("
        SELECT
            CASE
                WHEN dest_type = 'chantier' THEN dest_id
                WHEN source_type = 'chantier' THEN source_id
                ELSE NULL
            END AS chantier_id
        FROM stock_mouvements
        WHERE stock_id = :sid
          AND (
                dest_type = 'chantier'
             OR source_type = 'chantier'
          )
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    ");
    $qCid->execute([':sid' => $articleId]);
    $cid = $qCid->fetchColumn();
    $chantierId = $cid ? (int)$cid : null;
}

/* -------------------------------------------------------
   Upload optionnel (pour déclarer_panne)
------------------------------------------------------- */
$fichierPath = null;
if (!empty($_FILES['fichier']) && is_uploaded_file($_FILES['fichier']['tmp_name'])) {
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    $mime = mime_content_type($_FILES['fichier']['tmp_name']) ?: '';
    if (!in_array($mime, $allowed, true)) {
        jexit(400, ['ok' => false, 'msg' => 'Type de fichier non autorisé']);
    }
    $baseDir = dirname(__DIR__, 2) . '/uploads/etat/';
    if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true)) {
        jexit(500, ['ok' => false, 'msg' => 'Impossible de créer le dossier de stockage']);
    }
    $ext  = strtolower(pathinfo($_FILES['fichier']['name'], PATHINFO_EXTENSION));
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . ($ext ? '.' . $ext : '');
    $dest = $baseDir . $name;
    if (!move_uploaded_file($_FILES['fichier']['tmp_name'], $dest)) {
        jexit(500, ['ok' => false, 'msg' => 'Upload échoué']);
    }
    $fichierPath = 'uploads/etat/' . $name; // chemin relatif web
}

/* -------------------------------------------------------
   Traitements
------------------------------------------------------- */
try {
    $pdo->beginTransaction();

    /* --------- MAJ COMPTEUR (hour_meter) --------- */
    if ($action === 'compteur_maj') {
        if ($maintenanceMode !== 'hour_meter') {
            $pdo->rollBack();
            jexit(400, ['ok' => false, 'msg' => "Cet article n'est pas en mode compteur d'heures"]);
        }

        $val = $hours ?? $valeurInt;
        if (!is_int($val) || $val < 0) {
            $pdo->rollBack();
            jexit(400, ['ok' => false, 'msg' => 'Valeur du compteur invalide']);
        }

        // Tolérance INCREMENT : si la valeur postée est < initial ET < compteur courant, on l'interprète comme un delta
        $cur     = (int)($art['compteur_heures'] ?? 0);
        $initial = (int)($art['hour_meter_initial'] ?? 0);
        if ($val < $initial && $val < $cur) {
            $val = $cur + $val; // on ajoute le delta
        }

        // 1) log
        $source = ($role === 'chef') ? 'chef' : (($role === 'depot') ? 'depot' : (($role === 'administrateur') ? 'admin' : 'qr'));
        $insH = $pdo->prepare("
            INSERT INTO stock_hour_logs (stock_id, chantier_id, utilisateur_id, hours, source)
            VALUES (?, ?, ?, ?, ?)
        ");
        $insH->execute([$articleId, $chantierId, ($uid ?: null), $val, $source]);

        // 2) maj champ direct pour l'affichage rapide
        $pdo->prepare("UPDATE stock SET compteur_heures = :v WHERE id = :id")
            ->execute([':v' => $val, ':id' => $articleId]);

        // 3) fermer les demandes de relevé (tag = hour_meter_request)
        $pdo->prepare("
            UPDATE stock_alerts
               SET is_read = 1, archived_at = NOW()
             WHERE stock_id = :sid
               AND type = 'incident'
               AND url  = 'hour_meter_request'
               AND archived_at IS NULL
        ")->execute([':sid' => $articleId]);

        // 4) seuil entretien : initial + threshold (défaut 100h)
        $threshold = (int)($art['maintenance_threshold'] ?? 100);
        if ($threshold <= 0) {
            $threshold = 100;
        }
        $limit = $initial + $threshold;

        // (debug léger en logs)
        error_log(sprintf(
            '[MAJ_COMPTEUR] stock_id=%d, initial=%d, threshold=%d, limit=%d, posted=%d, cur_before=%d',
            $articleId,
            $initial,
            $threshold,
            $limit,
            $val,
            $cur
        ));

        if ($val >= $limit) {
            create_maintenance_alert($pdo, $articleId, (int)$art['entreprise_id'], $val, $limit);
        }

        // 5) historique
        $ins = $pdo->prepare("
            INSERT INTO article_etats
              (entreprise_id, article_id, chantier_id, profil_qr, action, valeur_int, commentaire, fichier, created_by)
            VALUES
              (:eid, :aid, :cid, :profil, 'compteur_maj', :val, NULL, NULL, :uid)
        ");
        $ins->execute([
            ':eid'    => (int)$art['entreprise_id'],
            ':aid'    => $articleId,
            ':cid'    => ($chantierId ?: null),
            ':profil' => $profil,
            ':val'    => $val,
            ':uid'    => ($uid ?: null),
        ]);

        $pdo->commit();
        jexit(200, ['ok' => true]);
    }

    /* --------- DÉCLARER PANNE (electrical/other) --------- */
    if ($action === 'declarer_panne') {
        if ($comment === '') {
            $pdo->rollBack();
            jexit(400, ['ok' => false, 'msg' => 'Description obligatoire']);
        }

        // Lecture éventuelle du compteur saisi
        $valOpt = null;
        if ($hours !== null) {
            if (!is_int($hours) || $hours < 0) {
                $pdo->rollBack();
                jexit(400, ['ok' => false, 'msg' => 'Compteur invalide']);
            }
            $valOpt = $hours;
        } elseif ($valeurInt !== null) {
            if (!is_int($valeurInt) || $valeurInt < 0) {
                $pdo->rollBack();
                jexit(400, ['ok' => false, 'msg' => 'Compteur invalide']);
            }
            $valOpt = $valeurInt;
        }

        // Idempotence : si une alerte identique vient d'être créée, on n'en recrée pas
        if ($dupId = recent_problem_exists($pdo, $articleId, $comment)) {
            $chk = $pdo->prepare("SELECT id FROM article_etats WHERE alert_id = :aid LIMIT 1");
            $chk->execute([':aid' => $dupId]);
            $hasHist = (bool)$chk->fetchColumn();

            if (!$hasHist) {
                $insDup = $pdo->prepare("
                    INSERT INTO article_etats
                      (entreprise_id, article_id, chantier_id, profil_qr, action, valeur_int, commentaire, fichier, created_by, alert_id)
                    VALUES
                      (:eid, :aid, :cid, :profil, 'declarer_panne', :val, :com, :file, :uid, :alert_id)
                ");
                $insDup->execute([
                    ':eid'      => (int)$art['entreprise_id'],
                    ':aid'      => $articleId,
                    ':cid'      => ($chantierId ?: null),
                    ':profil'   => $profil,
                    ':val'      => $valOpt,
                    ':com'      => $comment,
                    ':file'     => $fichierPath,
                    ':uid'      => ($uid ?: null),
                    ':alert_id' => $dupId,
                ]);
            }

            $pdo->commit();
            jexit(200, [
                'ok'    => true,
                'alert' => [
                    'id'         => $dupId,
                    'message'    => $comment,
                    'created_at' => now(),
                    'url'        => 'problem',
                    'is_read'    => 0,
                ],
            ]);
        }

        // passe en panne
        $pdo->prepare("UPDATE stock SET panne=1 WHERE id=:id")->execute([':id' => $articleId]);

        // si compteur fourni : on met à jour le stock + on log l'heure
        if ($valOpt !== null) {
            $source = ($role === 'chef') ? 'chef' : (($role === 'depot') ? 'depot' : (($role === 'administrateur') ? 'admin' : 'qr'));
            $insH = $pdo->prepare("
                INSERT INTO stock_hour_logs (stock_id, chantier_id, utilisateur_id, hours, source)
                VALUES (?, ?, ?, ?, ?)
            ");
            $insH->execute([$articleId, $chantierId, ($uid ?: null), $valOpt, $source]);

            $pdo->prepare("UPDATE stock SET compteur_heures = :v WHERE id = :id")
                ->execute([':v' => $valOpt, ':id' => $articleId]);
        }

        // alerte problème (admin + dépôt si "autre", avec idempotence par rôle)
        $alertId = 0;
        $createdAt = null;

        try {
            // ADMIN — évite doublon admin
            $existingAdmin = recent_alert_exists_role($pdo, $articleId, 'problem', $comment, 'admin');
            if ($existingAdmin) {
                $alertId = $existingAdmin;
            } else {
                $insAdmin = $pdo->prepare("
            INSERT INTO stock_alerts (entreprise_id, stock_id, type, message, url, target_role, created_at, is_read)
            VALUES (:eid, :sid, 'incident', :msg, 'problem', 'admin', NOW(), 0)
        ");
                $insAdmin->execute([
                    ':eid' => (int)$art['entreprise_id'],
                    ':sid' => $articleId,
                    ':msg' => $comment
                ]);
                $alertId = (int)$pdo->lastInsertId();
            }

            // DÉPÔT — seulement si pas hour_meter, et évite doublon dépôt
            if ($maintenanceMode !== 'hour_meter') {
                $existingDepot = recent_alert_exists_role($pdo, $articleId, 'problem', $comment, 'depot');
                if (!$existingDepot) {
                    $insDepot = $pdo->prepare("
                INSERT INTO stock_alerts (entreprise_id, stock_id, type, message, url, target_role, created_at, is_read)
                VALUES (:eid, :sid, 'incident', :msg, 'problem', 'depot', NOW(), 0)
            ");
                    $insDepot->execute([
                        ':eid' => (int)$art['entreprise_id'],
                        ':sid' => $articleId,
                        ':msg' => $comment
                    ]);
                }
            }

            // récupérer la date de l'alerte admin
            $st = $pdo->prepare("SELECT created_at FROM stock_alerts WHERE id = :id");
            $st->execute([':id' => $alertId]);
            $createdAt = (string)$st->fetchColumn();
        } catch (Throwable $e) {
            // Fallback : schéma sans target_role — éviter doublon sans rôle
            $existingAny = recent_alert_exists_role($pdo, $articleId, 'problem', $comment, null);
            if ($existingAny) {
                $alertId = $existingAny;
                $createdAt = now();
            } else {
                $insAlert = $pdo->prepare("
            INSERT INTO stock_alerts (entreprise_id, stock_id, type, message, url, created_at, is_read)
            VALUES (:eid, :sid, 'incident', :msg, 'problem', NOW(), 0)
        ");
                $insAlert->execute([
                    ':eid' => (int)$art['entreprise_id'],
                    ':sid' => $articleId,
                    ':msg' => $comment
                ]);
                $alertId  = (int)$pdo->lastInsertId();
                $createdAt = now();
            }
        }


        // historique
        $ins = $pdo->prepare("
            INSERT INTO article_etats
              (entreprise_id, article_id, chantier_id, profil_qr, action, valeur_int, commentaire, fichier, created_by, alert_id)
            VALUES
              (:eid, :aid, :cid, :profil, 'declarer_panne', :val, :com, :file, :uid, :alert_id)
        ");
        $ins->execute([
            ':eid'      => (int)$art['entreprise_id'],
            ':aid'      => $articleId,
            ':cid'      => ($chantierId ?: null),
            ':profil'   => $profil,
            ':val'      => $valOpt,
            ':com'      => $comment,
            ':file'     => $fichierPath,
            ':uid'      => ($uid ?: null),
            ':alert_id' => $alertId,
        ]);

        $pdo->commit();
        jexit(200, [
            'ok'    => true,
            'alert' => [
                'id'         => $alertId,
                'message'    => $comment,
                'created_at' => (string)$createdAt,
                'url'        => 'problem',
                'is_read'    => 0,
            ],
        ]);
    }

    /* --------- DÉCLARER OK (clore toutes les pannes) --------- */
    if ($action === 'declarer_ok') {
        $pdo->prepare("UPDATE stock SET panne=0 WHERE id=:id")->execute([':id' => $articleId]);

        $pdo->prepare("
            UPDATE stock_alerts
               SET is_read=1, archived_at=NOW()
             WHERE stock_id=:sid
               AND archived_at IS NULL
               AND type='incident'
               AND (url IN ('problem','generic') OR url IS NULL)
        ")->execute([':sid' => $articleId]);

        $curHours = (int)$pdo->query("SELECT compteur_heures FROM stock WHERE id = {$articleId}")->fetchColumn();

        $ins = $pdo->prepare("
            INSERT INTO article_etats
              (entreprise_id, article_id, chantier_id, profil_qr, action, valeur_int, commentaire, fichier, created_by)
            VALUES
              (:eid, :aid, :cid, :profil, 'declarer_ok', :val, NULL, NULL, :uid)
        ");
        $ins->execute([
            ':eid'    => (int)$art['entreprise_id'],
            ':aid'    => $articleId,
            ':cid'    => ($chantierId ?: null),
            ':profil' => $profil,
            ':val'    => $curHours,
            ':uid'    => ($uid ?: null),
        ]);

        $pdo->commit();
        jexit(200, ['ok' => true]);
    }

    /* --------- Résoudre UNE alerte ciblée (archive le groupe admin+depot) --------- */
if ($action === 'resolve_one') {
    $alertId = (int)($_POST['alert_id'] ?? 0);
    if ($alertId <= 0) {
        $pdo->rollBack();
        jexit(400, ['ok' => false, 'msg' => 'alert_id manquant']);
    }

    // 1) Récupère l’alerte cliquée (clé de regroupement)
    $st = $pdo->prepare("
      SELECT id, stock_id, type, url, TRIM(message) AS message, created_at
      FROM stock_alerts
      WHERE id = :id
        AND stock_id = :sid
        AND archived_at IS NULL
        AND type = 'incident'
      LIMIT 1
    ");
    $st->execute([':id' => $alertId, ':sid' => $articleId]);
    $a = $st->fetch(PDO::FETCH_ASSOC);

    // Déjà archivée ? On ne fait qu’un retour OK
    if (!$a) {
        $pdo->commit();
        jexit(200, ['ok' => true, 'resolved_ids' => []]);
    }

    $createdMinute = substr((string)$a['created_at'], 0, 16);
    $msgKey        = trim((string)$a['message']);
    $urlKey        = (string)$a['url'];

    // 2) Lister toutes les jumelles ouvertes de ce “groupe”
    $st = $pdo->prepare("
      SELECT id
      FROM stock_alerts
      WHERE stock_id = :sid
        AND archived_at IS NULL
        AND type = 'incident'
        AND url = :url
        AND TRIM(message) = :msg
        AND LEFT(created_at,16) = :min
    ");
    $st->execute([
        ':sid' => $articleId,
        ':url' => $urlKey,
        ':msg' => $msgKey,
        ':min' => $createdMinute,
    ]);
    $ids = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'id'));
    if (empty($ids)) $ids = [$alertId];

    // 3) Archiver toutes ces alertes en une fois
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("
      UPDATE stock_alerts
         SET is_read = 1, archived_at = NOW(), archived_by = ?
       WHERE id IN ($in) AND archived_at IS NULL
    ");
    $params = array_merge([$uid ?: null], $ids);
    $st->execute($params);

    // 4) Si plus AUCUNE alerte “problem/generic” ouverte, on repasse l’article à OK
    $q = $pdo->prepare("
        SELECT COUNT(*)
        FROM stock_alerts
        WHERE stock_id=:sid
          AND archived_at IS NULL
          AND type='incident'
          AND (url IN ('problem','generic') OR (url IS NULL AND type='incident'))
    ");
    $q->execute([':sid' => $articleId]);
    $remain = (int)$q->fetchColumn();
    if ($remain === 0) {
        $pdo->prepare("UPDATE stock SET panne=0 WHERE id=:id")->execute([':id' => $articleId]);
    }

    // 5) Insérer UNE seule ligne historique (idempotent)
    $refId = min($ids); // identifiant “stable” du groupe, pour afficher #xxx
    $curHours = (int)$pdo->query("SELECT compteur_heures FROM stock WHERE id = {$articleId}")->fetchColumn();

    // évite les doublons si double-clic / seconde jumelle :
    $check = $pdo->prepare("
      SELECT 1 FROM article_etats
      WHERE article_id = :aid AND alert_id = :ref AND action = 'declarer_ok'
      LIMIT 1
    ");
    $check->execute([':aid' => $articleId, ':ref' => $refId]);
    if (!$check->fetchColumn()) {
        $i = $pdo->prepare("
            INSERT INTO article_etats
              (entreprise_id, article_id, chantier_id, profil_qr, action, valeur_int, commentaire, fichier, created_by, alert_id)
            VALUES
              (:eid, :aid, :cid, :profil, 'declarer_ok', :val, :com, NULL, :uid, :ref)
        ");
        $i->execute([
            ':eid'    => (int)$art['entreprise_id'],
            ':aid'    => $articleId,
            ':cid'    => ($chantierId ?: null),
            ':profil' => $profil,
            ':val'    => $curHours,
            ':com'    => 'problème #' . $refId . ' résolu',
            ':uid'    => ($uid ?: null),
            ':ref'    => $refId,
        ]);
    }

    $pdo->commit();
    jexit(200, ['ok' => true, 'resolved_ids' => $ids]);
}


    /* --------- Action non supportée --------- */
    $pdo->rollBack();
    jexit(400, ['ok' => false, 'msg' => 'Action inconnue']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('ajax_article_etat_save: ' . $e->getMessage());
    jexit(500, ['ok' => false, 'msg' => 'Erreur interne']);
}
