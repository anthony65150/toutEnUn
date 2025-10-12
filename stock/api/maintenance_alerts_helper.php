<?php
declare(strict_types=1);

function maybeTriggerMaintenanceAlert(PDO $pdo, int $stockId, ?int $entId = null): void {
    // On suppose que tu as ces 3 colonnes dans ta table stock :
    // hour_meter_current, hour_meter_last_service, maintenance_interval_hours
    $sql = "SELECT id, entreprise_id, hour_meter_current, hour_meter_last_service, maintenance_interval_hours
            FROM stock WHERE id=:sid" . ($entId ? " AND entreprise_id=:eid" : "");
    $st = $pdo->prepare($sql);
    $params = [':sid'=>$stockId];
    if ($entId) $params[':eid'] = $entId;
    $st->execute($params);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) return;

    $current  = (int)$s['hour_meter_current'];
    $lastSrv  = (int)$s['hour_meter_last_service'];
    $interval = max(1, (int)$s['maintenance_interval_hours']);
    $dueAt    = $lastSrv + $interval;

    if ($current < $dueAt) return; // seuil pas encore atteint

    // Vérifier si une alerte similaire existe déjà
    $chk = $pdo->prepare("
        SELECT id FROM stock_alerts 
        WHERE stock_id=:sid 
          AND type='incident' 
          AND url='maintenance_due'
          AND is_read=0
        LIMIT 1
    ");
    $chk->execute([':sid'=>$stockId]);
    if ($chk->fetchColumn()) return;

    // Créer la nouvelle alerte
    $msg = "Entretien à prévoir : compteur {$current}h (seuil {$dueAt}h).";
    $ins = $pdo->prepare("
        INSERT INTO stock_alerts (entreprise_id, stock_id, type, message, url, created_at, is_read)
        SELECT entreprise_id, id, 'incident', :msg, 'maintenance_due', NOW(), 0
        FROM stock WHERE id=:sid
    ");
    $ins->execute([':sid'=>$stockId, ':msg'=>$msg]);
}
