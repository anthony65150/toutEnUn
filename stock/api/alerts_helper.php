<?php
declare(strict_types=1);

// /stock/api/alerts_helper.php
function create_alert(PDO $pdo, int $entrepriseId, int $stockId, string $type, string $message, string $targetRole = null, ?int $createdBy = null, ?int $chantierId = null, ?string $url = null): int {
    // Essai avec target_role + stock_id
    try {
        $sql = "
            INSERT INTO stock_alerts (entreprise_id, stock_id, type, message, url, target_role, created_by, chantier_id, is_read, created_at)
            VALUES (:eid, :sid, :type, :msg, :url, :role, :cb, :cid, 0, NOW())
        ";
        $st = $pdo->prepare($sql);
        $st->execute([
            ':eid'=>$entrepriseId, ':sid'=>$stockId, ':type'=>$type, ':msg'=>$message,
            ':url'=>$url, ':role'=>$targetRole, ':cb'=>$createdBy, ':cid'=>$chantierId
        ]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        // Fallback sans target_role
        $sql = "
            INSERT INTO stock_alerts (entreprise_id, stock_id, type, message, url, created_by, chantier_id, is_read, created_at)
            VALUES (:eid, :sid, :type, :msg, :url, :cb, :cid, 0, NOW())
        ";
        $st = $pdo->prepare($sql);
        $st->execute([
            ':eid'=>$entrepriseId, ':sid'=>$stockId, ':type'=>$type, ':msg'=>$message,
            ':url'=>$url, ':cb'=>$createdBy, ':cid'=>$chantierId
        ]);
        return (int)$pdo->lastInsertId();
    }
}
