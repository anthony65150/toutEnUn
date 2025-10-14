<?php
declare(strict_types=1);

// /stock/api/alerts_unread_count.php
require_once __DIR__ . '/../../config/init.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['utilisateurs'])) {
    echo json_encode(['ok' => false, 'count' => 0]); exit;
}

$u     = $_SESSION['utilisateurs'];
$role  = (string)($u['fonction'] ?? '');
$uid   = (int)($u['id'] ?? 0);
$entId = (int)($u['entreprise_id'] ?? 0);

/* ======================================================
   Helpers de comptage
====================================================== */

/**
 * Admin : compte TOUTES les alertes d'incident non lues/non archivées
 * (pannes + entretiens + génériques + demandes de relevé)
 * limitées à l'entreprise courante.
 */
function count_admin_incidents(PDO $pdo, int $entId): int {
    $sql = "
        SELECT COUNT(*)
        FROM stock_alerts a
        WHERE a.entreprise_id = :eid
          AND a.archived_at IS NULL
          AND (a.is_read = 0 OR a.is_read IS NULL)
          AND a.type = 'incident'
          AND a.url IN ('problem','maintenance_due','generic','hour_meter_request')
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':eid' => $entId]);
    return (int)$st->fetchColumn();
}

/**
 * Chef / Dépôt : conserve ton système de notifications texte
 * (messages qui contiennent “Relevé d'heures” ou “compteur”)
 */
function count_hourmeter_notifs(PDO $pdo, int $uid, int $entId): int {
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
        // Fallback léger si le schéma diffère
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

/* ======================================================
   Routage par rôle
====================================================== */
try {
    // ADMIN (administrateur/admin) : pannes + entretiens + génériques + demandes de relevé
    if ($role === 'administrateur' || $role === 'admin') {
        $count = count_admin_incidents($pdo, $entId);
        echo json_encode(['ok' => true, 'count' => $count]); exit;
    }

    // CHEF
    if ($role === 'chef') {
        $count = count_hourmeter_notifs($pdo, $uid, $entId);
        echo json_encode(['ok' => true, 'count' => $count]); exit;
    }

    // DEPOT
    if ($role === 'depot') {
        $count = count_hourmeter_notifs($pdo, $uid, $entId);
        echo json_encode(['ok' => true, 'count' => $count]); exit;
    }

    // Autres rôles : pas de cloche
    echo json_encode(['ok' => true, 'count' => 0]);
} catch (Throwable $e) {
    error_log('alerts_unread_count error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'count' => 0]);
}
