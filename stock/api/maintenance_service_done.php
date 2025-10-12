<?php
// /stock/api/maintenance_service_done.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/init.php';
header('Content-Type: application/json; charset=utf-8');

// Auth admin
if (empty($_SESSION['utilisateurs']) || !in_array(($_SESSION['utilisateurs']['fonction'] ?? ''), ['administrateur','admin'], true)) {
    http_response_code(403);
    echo json_encode(['ok'=>false, 'msg'=>'Droits insuffisants']);
    exit;
}

$entId   = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);
$userId  = (int)($_SESSION['utilisateurs']['id'] ?? 0);
$stockId = (int)($_POST['stock_id'] ?? 0);
// $csrf = (string)($_POST['csrf'] ?? ''); // décommente si tu veux valider le token

if ($stockId <= 0) {
    http_response_code(422);
    echo json_encode(['ok'=>false, 'msg'=>'stock_id manquant']);
    exit;
}

try {
    // Vérifier que l’article est bien dans l’entreprise
    $q = $pdo->prepare("SELECT id, entreprise_id, compteur_heures FROM stock WHERE id=:sid LIMIT 1");
    $q->execute([':sid'=>$stockId]);
    $s = $q->fetch(PDO::FETCH_ASSOC);
    if (!$s) { http_response_code(404); echo json_encode(['ok'=>false,'msg'=>'Article introuvable']); exit; }

    if ($entId > 0 && (int)$s['entreprise_id'] !== $entId) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'msg'=>"Article hors de votre entreprise"]);
        exit;
    }

    $curHours = (int)($s['compteur_heures'] ?? 0);

    $pdo->beginTransaction();

    // 👉 Ton schéma utilise hour_meter_initial + maintenance_threshold
    // On marque l’entretien fait en posant le nouveau “point de départ”
    $u = $pdo->prepare("UPDATE stock SET hour_meter_initial = :hmi WHERE id = :sid");
    $u->execute([':hmi'=>$curHours, ':sid'=>$stockId]);

    // Clore toutes les alertes d’entretien ouvertes pour cet article
    $u2 = $pdo->prepare("
        UPDATE stock_alerts
           SET is_read = 1,
               archived_at = NOW(),
               archived_by = :uid
         WHERE stock_id = :sid
           AND type = 'incident'
           AND url  = 'maintenance_due'
           AND archived_at IS NULL
    ");
    $u2->execute([':sid'=>$stockId, ':uid'=>$userId]);

    // Historiser l’action (facultatif)
    $ins = $pdo->prepare("
        INSERT INTO article_etats
          (entreprise_id, article_id, profil_qr, action, valeur_int, commentaire, fichier, created_by)
        SELECT s.entreprise_id, s.id, 'compteur_heures', 'entretien_effectue', :val, NULL, NULL, :uid
        FROM stock s WHERE s.id=:sid
    ");
    $ins->execute([':sid'=>$stockId, ':val'=>$curHours, ':uid'=>$userId]);

    $pdo->commit();

    echo json_encode(['ok'=>true, 'new_initial'=>$curHours]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('maintenance_service_done: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'msg'=>'Erreur serveur']);
}
