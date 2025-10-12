<?php

declare(strict_types=1);

// /stock/ajax/ajax_article_etat_save.php
require_once __DIR__ . '/../../config/init.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function jexit(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/* -------------------------------------------------------
   Helpers
------------------------------------------------------- */
function article_by_id(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("
    SELECT id, entreprise_id, maintenance_mode,
           compteur_heures,
           COALESCE(hour_meter_initial, 0)      AS hour_meter_initial,
           COALESCE(maintenance_threshold, 150) AS maintenance_threshold
    FROM stock
    WHERE id = :id
    LIMIT 1
  ");
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function maintenance_alert_exists(PDO $pdo, int $stockId): bool
{
    $q = $pdo->prepare("
    SELECT id
    FROM stock_alerts
    WHERE stock_id = :sid
      AND type = 'incident'
      AND url = 'maintenance_due'
      AND is_read = 0
      AND archived_at IS NULL
    LIMIT 1
  ");
    $q->execute([':sid' => $stockId]);
    return (bool)$q->fetchColumn();
}

function create_maintenance_alert(PDO $pdo, int $stockId, int $entrepriseId, int $current, int $limit): void
{
    if (maintenance_alert_exists($pdo, $stockId)) return; // anti-doublon
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

/* -------------------------------------------------------
   Paramètres
------------------------------------------------------- */
$actionRaw   = (string)($_POST['action'] ?? '');
$articleId   = (int)($_POST['article_id'] ?? ($_POST['stock_id'] ?? 0));
$valeurInt   = isset($_POST['valeur_int']) ? (int)$_POST['valeur_int'] : null;   // alias compteur_maj
$hours       = isset($_POST['hours']) ? (int)$_POST['hours'] : null;             // alias QR / modale
$chantierId  = isset($_POST['chantier_id']) ? (int)$_POST['chantier_id'] : null; // pour logs
$comment     = trim((string)($_POST['commentaire'] ?? ($_POST['message'] ?? '')));

$actionMap = [
    'hour_meter'      => 'compteur_maj',
    'declare_problem' => 'declarer_panne',
    'resolve_problem' => 'declarer_ok',
    'resolve_one'     => 'resolve_one',
];
$action = $actionMap[$actionRaw] ?? $actionRaw;

/* -------------------------------------------------------
   Auth / contexte
------------------------------------------------------- */
$isLogged = isset($_SESSION['utilisateurs']);
$uid      = $isLogged ? (int)$_SESSION['utilisateurs']['id'] : 0;
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

        // 4) seuil entretien : initial + threshold
        $initial   = (int)($art['hour_meter_initial'] ?? 0);
        $threshold = (int)($art['maintenance_threshold'] ?? 150);
        $limit     = $initial + $threshold;

        if ($val >= $limit) {
            create_maintenance_alert($pdo, $articleId, (int)$art['entreprise_id'], $val, $limit);
        }

        // 5) historique
        $ins = $pdo->prepare("
      INSERT INTO article_etats
        (entreprise_id, article_id, profil_qr, action, valeur_int, commentaire, fichier, created_by)
      VALUES
        (:eid, :aid, :profil, 'compteur_maj', :val, NULL, NULL, :uid)
    ");
        $ins->execute([
            ':eid' => (int)$art['entreprise_id'],
            ':aid' => $articleId,
            ':profil' => $profil,
            ':val' => $val,
            ':uid' => ($uid ?: null),
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
        } elseif ($valeurInt !== null) { // alias
            if (!is_int($valeurInt) || $valeurInt < 0) {
                $pdo->rollBack();
                jexit(400, ['ok' => false, 'msg' => 'Compteur invalide']);
            }
            $valOpt = $valeurInt;
        }

        // 🔒 Idempotence : si une alerte identique vient d'être créée, on n'en recrée pas
        if ($dupId = recent_problem_exists($pdo, $articleId, $comment)) {
            // Vérifie s'il y a déjà un historique lié à cette alerte
            $chk = $pdo->prepare("SELECT id FROM article_etats WHERE alert_id = :aid LIMIT 1");
            $chk->execute([':aid' => $dupId]);
            $hasHist = (bool)$chk->fetchColumn();

            if (!$hasHist) {
                // (optionnel) tu peux garder ceci si tu veux absolument tracer UNE fois
                $insDup = $pdo->prepare("
      INSERT INTO article_etats
        (entreprise_id, article_id, profil_qr, action, valeur_int, commentaire, fichier, created_by, alert_id)
      VALUES
        (:eid, :aid, :profil, 'declarer_panne', :val, :com, :file, :uid, :alert_id)
    ");
                $insDup->execute([
                    ':eid' => (int)$art['entreprise_id'],
                    ':aid' => $articleId,
                    ':profil' => $profil,
                    ':val' => $valOpt,
                    ':com' => $comment,
                    ':file' => $fichierPath,
                    ':uid' => ($uid ?: null),
                    ':alert_id' => $dupId,
                ]);
            }

            $pdo->commit();
            jexit(200, [
                'ok' => true,
                'alert' => [
                    'id' => $dupId,
                    'message' => $comment,
                    'created_at' => now(),
                    'url' => 'problem',
                    'is_read' => 0,
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

        // alerte problème (type=incident, tag=problem)
        $insAlert = $pdo->prepare("
      INSERT INTO stock_alerts (entreprise_id, stock_id, type, message, url, created_at, is_read)
      VALUES (:eid, :sid, 'incident', :msg, 'problem', NOW(), 0)
    ");
        $insAlert->execute([
            ':eid' => (int)$art['entreprise_id'],
            ':sid' => $articleId,
            ':msg' => $comment
        ]);
        $alertId = (int)$pdo->lastInsertId();

        // récup created_at
        $st = $pdo->prepare("SELECT created_at FROM stock_alerts WHERE id = :id");
        $st->execute([':id' => $alertId]);
        $createdAt = (string)$st->fetchColumn();

        // historique (valeur_int = compteur si fourni, fichier = photo, + lien à l'alerte)
        $ins = $pdo->prepare("
      INSERT INTO article_etats
        (entreprise_id, article_id, profil_qr, action, valeur_int, commentaire, fichier, created_by, alert_id)
      VALUES
        (:eid, :aid, :profil, 'declarer_panne', :val, :com, :file, :uid, :alert_id)
    ");
        $ins->execute([
            ':eid' => (int)$art['entreprise_id'],
            ':aid' => $articleId,
            ':profil' => $profil,
            ':val' => $valOpt,
            ':com' => $comment,
            ':file' => $fichierPath,
            ':uid' => ($uid ?: null),
            ':alert_id' => $alertId,
        ]);

        $pdo->commit();
        jexit(200, [
            'ok' => true,
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

        // on archive toutes les alertes ouvertes taggées problem/generic
        $pdo->prepare("
      UPDATE stock_alerts
         SET is_read=1, archived_at=NOW()
       WHERE stock_id=:sid
         AND archived_at IS NULL
         AND type='incident'
         AND (url IN ('problem','generic') OR url IS NULL)
    ")->execute([':sid' => $articleId]);

        // compteur courant
        $curHours = (int)$pdo->query("SELECT compteur_heures FROM stock WHERE id = {$articleId}")->fetchColumn();

        // historique avec valeur_int
        $ins = $pdo->prepare("
    INSERT INTO article_etats
      (entreprise_id, article_id, profil_qr, action, valeur_int, commentaire, fichier, created_by)
    VALUES
      (:eid, :aid, :profil, 'declarer_ok', :val, NULL, NULL, :uid)
  ");
        $ins->execute([
            ':eid'    => (int)$art['entreprise_id'],
            ':aid'    => $articleId,
            ':profil' => $profil,
            ':val'    => $curHours, // 👈
            ':uid'    => ($uid ?: null),
        ]);

        $pdo->commit();
        jexit(200, ['ok' => true]);
    }

    /* --------- Résoudre UNE alerte ciblée --------- */
    if ($action === 'resolve_one') {
        $alertId = (int)($_POST['alert_id'] ?? 0);
        if ($alertId <= 0) {
            $pdo->rollBack();
            jexit(400, ['ok' => false, 'msg' => 'alert_id manquant']);
        }

        // archive l’alerte
        $u = $pdo->prepare("UPDATE stock_alerts SET is_read=1, archived_at=NOW() WHERE id=:id AND stock_id=:sid");
        $u->execute([':id' => $alertId, ':sid' => $articleId]);

        // s'il ne reste plus d'alertes ouvertes -> repasse l'article en OK
        $q = $pdo->prepare("
      SELECT COUNT(*)
      FROM stock_alerts
      WHERE stock_id=:sid
        AND archived_at IS NULL
        AND (
              is_read = 0
           OR (type='incident' AND url IN ('problem','generic'))
        )
    ");
        $q->execute([':sid' => $articleId]);
        $remain = (int)$q->fetchColumn();
        if ($remain === 0) {
            $pdo->prepare("UPDATE stock SET panne=0 WHERE id=:id")->execute([':id' => $articleId]);
        }

        // compteur courant au moment de la résolution
        $curHours = (int)$pdo->query("SELECT compteur_heures FROM stock WHERE id = {$articleId}")->fetchColumn();

        // historique
        $i = $pdo->prepare("
  INSERT INTO article_etats
    (entreprise_id, article_id, profil_qr, action, valeur_int, commentaire, fichier, created_by, alert_id)
  VALUES
    (:eid, :aid, :profil, 'declarer_ok', :val, :com, NULL, :uid, :alert_id)
");
$i->execute([
  ':eid'      => (int)$art['entreprise_id'],
  ':aid'      => $articleId,
  ':profil'   => $profil,
  ':val'      => $curHours,                       // valeur du compteur au moment de la résolution
  ':com'      => 'problème #'.$alertId.' résolu', // 👈 wording demandé
  ':uid'      => ($uid ?: null),
  ':alert_id' => $alertId,                        // 👈 on relie à l’alerte
]);



        $pdo->commit();
        jexit(200, ['ok' => true]);
    }

    /* --------- Action non supportée --------- */
    $pdo->rollBack();
    jexit(400, ['ok' => false, 'msg' => 'Action inconnue']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('ajax_article_etat_save: ' . $e->getMessage());
    jexit(500, ['ok' => false, 'msg' => 'Erreur interne']);
}
