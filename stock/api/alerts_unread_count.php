<?php

declare(strict_types=1);

// /stock/api/alerts_unread_count.php
require_once __DIR__ . '/../../config/init.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['utilisateurs'])) {
    echo json_encode(['ok' => false, 'count' => 0]);
    exit;
}

$u     = $_SESSION['utilisateurs'];
$role  = (string)($u['fonction'] ?? '');
$uid   = (int)($u['id'] ?? 0);
$entId = (int)($u['entreprise_id'] ?? 0);

/* ======================================================
   Helpers
====================================================== */
function alerts_has_col(PDO $pdo, string $col): bool
{
    try {
        $q = $pdo->prepare("SHOW COLUMNS FROM stock_alerts LIKE :c");
        $q->execute([':c' => $col]);
        return (bool)$q->fetch();
    } catch (Throwable $e) {
        return false;
    }
}
/** Détecte si la colonne target_role existe (pour filtrer proprement par rôle) */
function alerts_has_target_role(PDO $pdo): bool
{
    static $has = null;
    if ($has !== null) return $has;
    try {
        $q = $pdo->query("SHOW COLUMNS FROM stock_alerts LIKE 'target_role'");
        $has = (bool)$q->fetch();
    } catch (Throwable $e) {
        $has = false;
    }
    return $has;
}


/** ADMIN : compte + last_id (filtré par rôle admin si la colonne existe) */
function count_admin_incidents(PDO $pdo, int $entId): array
{
    // tronc commun de filtre
    $base = "
        FROM stock_alerts a
        JOIN stock s ON s.id = a.stock_id
        WHERE s.entreprise_id = :eid
          AND a.archived_at IS NULL
          AND (a.is_read = 0 OR a.is_read IS NULL)
          AND a.type = 'incident'
          AND a.url IN ('problem','maintenance_due','generic','hour_meter_request')
    ";

    // 1) On tente AVEC target_role (cas normal chez toi)
    try {
        $sql = "SELECT COUNT(*) AS c, MAX(a.id) AS last_id
                $base
                  AND (a.target_role = 'admin' OR a.target_role IS NULL)";
        $st  = $pdo->prepare($sql);
        $st->execute([':eid' => $entId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 'last_id' => null];
        return [(int)$row['c'], $row['last_id'] ? (int)$row['last_id'] : null];
    } catch (Throwable $e) {
        // 2) Fallback SANS target_role (vieux schéma)
        $sql = "SELECT COUNT(*) AS c, MAX(a.id) AS last_id $base";
        $st  = $pdo->prepare($sql);
        $st->execute([':eid' => $entId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 'last_id' => null];
        return [(int)$row['c'], $row['last_id'] ? (int)$row['last_id'] : null];
    }
}




/** CHEF/DÉPÔT (legacy notifications texte) : messages contenant “Relevé d'heures” ou “compteur” */
function count_hourmeter_notifs(PDO $pdo, int $uid, int $entId): int
{
    $kw1 = "%Relevé d'heures%";
    $kw2 = "%compteur%";
    try {
        $sql = "
            SELECT COUNT(*) 
            FROM notifications n
            WHERE n.utilisateur_id = :uid
              AND (n.is_read = 0 OR n.is_read IS NULL)
              AND (n.message LIKE :kw1 OR n.message LIKE :kw2)
              " . ($entId > 0 ? " AND n.entreprise_id = :eid" : "") . "
        ";
        $params = [':uid' => $uid, ':kw1' => $kw1, ':kw2' => $kw2];
        if ($entId > 0) $params[':eid'] = $entId;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        // Fallback simple
        try {
            $sql = "
                SELECT COUNT(*) 
                FROM notifications n
                WHERE n.utilisateur_id = :uid
                  AND (n.message LIKE :kw1 OR n.message LIKE :kw2)
                  AND n.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ";
            $st = $pdo->prepare($sql);
            $st->execute([':uid' => $uid, ':kw1' => $kw1, ':kw2' => $kw2]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e2) {
            return 0;
        }
    }
}

/** DÉPÔT : unread `hour_meter_request` (les 2 variantes) + `incident/problem`, borné entreprise (+ target_role si présent) */
function count_depot_alerts(PDO $pdo, int $entId): int
{
    $HAS_TARGET = alerts_has_target_role($pdo);
    $sql = "
        SELECT COUNT(*)
        FROM stock_alerts a
        JOIN stock s ON s.id = a.stock_id
        WHERE s.entreprise_id = :eid
          AND a.archived_at IS NULL
          AND (a.is_read = 0 OR a.is_read IS NULL)
          AND (
                a.type = 'incident' AND a.url IN ('problem','hour_meter_request')
             OR a.type = 'hour_meter_request'
          )
    ";
    if ($HAS_TARGET) {
        $sql .= " AND a.target_role = 'depot'";
    }
    $st = $pdo->prepare($sql);
    $st->execute([':eid' => $entId]);
    return (int)$st->fetchColumn();
}

/* (Optionnel si tu veux basculer CHEF sur stock_alerts plus tard)
function count_chef_alerts(PDO $pdo, int $entId): int {
    $HAS_TARGET = alerts_has_target_role($pdo);
    $sql = "
        SELECT COUNT(*)
        FROM stock_alerts a
        JOIN stock s ON s.id = a.stock_id
        WHERE s.entreprise_id = :eid
          AND a.archived_at IS NULL
          AND (a.is_read = 0 OR a.is_read IS NULL)
          AND (
                a.type = 'incident' AND a.url IN ('problem','maintenance_due','generic','hour_meter_request')
             OR a.type = 'hour_meter_request'
          )
    ";
    if ($HAS_TARGET) $sql .= " AND a.target_role = 'chef'";
    $st = $pdo->prepare($sql);
    $st->execute([':eid' => $entId]);
    return (int)$st->fetchColumn();
}
*/


/* ======================================================
   Routage par rôle
====================================================== */
try {
    // ADMIN
    if ($role === 'administrateur' || $role === 'admin') {
        [$count, $lastId] = count_admin_incidents($pdo, $entId);
        echo json_encode(['ok' => true, 'count' => $count, 'last_id' => $lastId]);
        exit;
    }


    // CHEF (on garde tes notifications texte pour l’instant)
    if ($role === 'chef') {
        $count = count_hourmeter_notifs($pdo, $uid, $entId);
        // Si tu veux basculer sur stock_alerts plus tard : $count = count_chef_alerts($pdo, $entId);
        echo json_encode(['ok' => true, 'count' => $count]);
        exit;
    }

    // DÉPÔT → utilise désormais stock_alerts (relevés + pannes autres)
    if ($role === 'depot') {
        $count = count_depot_alerts($pdo, $entId);
        echo json_encode(['ok' => true, 'count' => $count]);
        exit;
    }

    // Autres rôles
    echo json_encode(['ok' => true, 'count' => 0]);
} catch (Throwable $e) {
    error_log('alerts_unread_count error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'count' => 0]);
}
