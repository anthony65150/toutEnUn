<?php
declare(strict_types=1);

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

/**
 * ADMIN — compte les alertes d’entretien à prévoir (seuil dépassé).
 * On utilise stock_alerts: type='incident', url='maintenance_due', is_read=0, non archivées.
 */
// Admin : compter les incidents stock_alerts non archivés de l'entreprise
if ($role === 'administrateur') {
    try {
        $sql = "
            SELECT COUNT(*)
            FROM stock_alerts sa
            JOIN stock s ON s.id = sa.stock_id
            WHERE s.entreprise_id = :eid
              AND sa.archived_at IS NULL
              AND sa.type = 'incident'           -- pannes + entretiens
              AND (sa.is_read = 0 OR sa.is_read IS NULL)
        ";
        $st = $pdo->prepare($sql);
        $st->execute([':eid' => $entId]);
        $count = (int)$st->fetchColumn();
        echo json_encode(['ok' => true, 'count' => $count]);
        exit;
    } catch (Throwable $e) {
        error_log('alerts_unread_count admin: '.$e->getMessage());
        echo json_encode(['ok' => true, 'count' => 0]);
        exit;
    }
}

function count_admin_maintenance_due(PDO $pdo, int $entId): int {
    $sql = "
        SELECT COUNT(*) 
        FROM stock_alerts a
        WHERE a.type = 'incident'
          AND a.url  = 'maintenance_due'
          AND (a.is_read = 0 OR a.is_read IS NULL)
          AND a.archived_at IS NULL
    ";
    $params = [];
    if ($entId > 0) {
        $sql .= " AND a.entreprise_id = :eid";
        $params[':eid'] = $entId;
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

/**
 * CHEF / DEPOT — ton comptage existant sur la table notifications (relevés d’heures).
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
        ";
        $params = [':uid' => $uid, ':kw1' => $kw1, ':kw2' => $kw2];

        if ($entId > 0) {
            $sql .= " AND n.entreprise_id = :eid";
            $params[':eid'] = $entId;
        }

        $st = $pdo->prepare($sql);
        $st->execute($params);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        // Fallback si le schéma diffère
    }

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
    } catch (Throwable $e) {
        return 0;
    }
}

try {
    // 🔔 ADMIN: cloche = nombre d'alertes d'entretien à prévoir
    if ($role === 'administrateur' || $role === 'admin') {
        $count = count_admin_maintenance_due($pdo, $entId);
        echo json_encode(['ok' => true, 'count' => $count]);
        exit;
    }

    // 🔔 CHEF: conserve ton comptage notifications "relevé d'heures"
    if ($role === 'chef') {
        $count = count_hourmeter_notifs($pdo, $uid, $entId);
        echo json_encode(['ok' => true, 'count' => $count]);
        exit;
    }

    // 🔔 DEPOT: idem
    if ($role === 'depot') {
        $count = count_hourmeter_notifs($pdo, $uid, $entId);
        echo json_encode(['ok' => true, 'count' => $count]);
        exit;
    }

    // autres rôles: pas de cloche
    echo json_encode(['ok' => true, 'count' => 0]);

} catch (Throwable $e) {
    error_log('alerts_unread_count error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'count' => 0]);
}
