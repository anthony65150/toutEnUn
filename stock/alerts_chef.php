<?php
// /stock/alerts_chef.php — liste les notifications “relevé d’heures” pour CHEF ou DEPOT
declare(strict_types=1);
require_once __DIR__ . '/../config/init.php';

if (empty($_SESSION['utilisateurs'])) { header('Location: /connexion.php'); exit; }

$u     = $_SESSION['utilisateurs'];
$role  = (string)($u['fonction'] ?? '');
$uid   = (int)($u['id'] ?? 0);
$entId = (int)($u['entreprise_id'] ?? 0);

if ($role !== 'chef' && $role !== 'depot') {
    $_SESSION['error_message'] = "Accès refusé.";
    header('Location: /index.php'); exit;
}

// Mots-clés robustes présents dans tes messages
$KW1 = "%Relevé d'heures%";
$KW2 = "%compteur%";

// =============== POST : marquer comme lu (unitaire ou tout) ===============
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $markId = isset($_POST['notif_id']) ? (int)$_POST['notif_id'] : 0;

    try {
        if ($markId > 0) {
            // 1) Récupérer la notif -> stock_id si dispo
            $row = null;
            try {
                $st = $pdo->prepare("
                    SELECT id, stock_id
                    FROM notifications
                    WHERE id = :id AND utilisateur_id = :uid
                    LIMIT 1
                ");
                $st->execute([':id'=>$markId, ':uid'=>$uid]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { $row = null; }

            // 2) Marquer la notif comme lue (fallback delete si pas de colonne is_read)
            try {
                $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id AND utilisateur_id = :uid")
                    ->execute([':id'=>$markId, ':uid'=>$uid]);
            } catch (Throwable $e) {
                $pdo->prepare("DELETE FROM notifications WHERE id = :id AND utilisateur_id = :uid")
                    ->execute([':id'=>$markId, ':uid'=>$uid]);
            }

            // 3) SYNC admin (stock_alerts)
            if (!empty($row['stock_id'])) {
                // a) Cas idéal avec stock_id -> on marque toutes les HMR de cet article
                $sql = "UPDATE stock_alerts a
                          JOIN stock s ON s.id = a.stock_id
                           SET a.is_read = 1
                        WHERE a.type = 'hour_meter_request'
                          AND a.archived_at IS NULL
                          AND a.stock_id = :sid";
                $p = [':sid' => (int)$row['stock_id']];
                if ($entId > 0) { $sql .= " AND s.entreprise_id = :eid"; $p[':eid'] = $entId; }
                try { $pdo->prepare($sql)->execute($p); } catch (Throwable $e) {}
            } else {
                // b) Fallback SANS stock_id -> on marque la DERNIÈRE alerte HMR de l’entreprise
                $sql = "UPDATE stock_alerts
                           SET is_read = 1
                         WHERE id = (
                           SELECT id FROM (
                             SELECT a.id
                               FROM stock_alerts a
                               ".($entId>0 ? "JOIN stock s ON s.id=a.stock_id AND s.entreprise_id = :eid" : "")."
                              WHERE a.type='hour_meter_request'
                                AND a.archived_at IS NULL
                              ORDER BY a.id DESC
                              LIMIT 1
                           ) t
                         )";
                $p = [];
                if ($entId > 0) $p[':eid'] = $entId;
                try { $pdo->prepare($sql)->execute($p); } catch (Throwable $e) {}
            }

        } else {
            // Tout marquer comme lu (chef / dépôt)
            $sqlN = "UPDATE notifications
                        SET is_read = 1
                      WHERE utilisateur_id = :uid
                        AND (message LIKE :kw1 OR message LIKE :kw2)";
            $pN = [':uid'=>$uid, ':kw1'=>$KW1, ':kw2'=>$KW2];
            if ($entId > 0) { $sqlN .= " AND entreprise_id = :eid"; $pN[':eid'] = $entId; }
            try { $pdo->prepare($sqlN)->execute($pN); } catch (Throwable $e) {}

            // Puis côté admin : passer à lu toutes les HMR de l’entreprise
            $sqlA = "UPDATE stock_alerts a
                       JOIN stock s ON s.id = a.stock_id
                        SET a.is_read = 1
                     WHERE a.type = 'hour_meter_request'
                       AND a.archived_at IS NULL";
            $pA = [];
            if ($entId > 0) { $sqlA .= " AND s.entreprise_id = :eid"; $pA[':eid'] = $entId; }
            try { $pdo->prepare($sqlA)->execute($pA); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {
        // on n'empêche pas l'affichage
    }

    header('Location: /stock/alerts_chef.php'); exit;
}
// ===================== /POST =====================

// Récupération des notifs (essaie de prendre stock_id si dispo)
$rows = [];
try {
    $sql = "
        SELECT id, message, created_at, COALESCE(is_read,0) AS is_read, stock_id
        FROM notifications
        WHERE utilisateur_id = :uid
          AND (message LIKE :kw1 OR message LIKE :kw2)
    ";
    $params = [':uid'=>$uid, ':kw1'=>$KW1, ':kw2'=>$KW2];
    if ($entId > 0) { $sql .= " AND entreprise_id = :eid"; $params[':eid'] = $entId; }
    $sql .= " ORDER BY is_read ASC, created_at DESC, id DESC";
    $st = $pdo->prepare($sql); $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Fallback si la colonne stock_id n’existe pas
    $st = $pdo->prepare("
        SELECT id, message, created_at, 0 AS is_read, NULL AS stock_id
        FROM notifications
        WHERE utilisateur_id = :uid
          AND (message LIKE :kw1 OR message LIKE :kw2)
        ORDER BY created_at DESC, id DESC
    ");
    $st->execute([':uid'=>$uid, ':kw1'=>$KW1, ':kw2'=>$KW2]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}

// Affichage
$pageTitle = "Alertes relevé d'heures";
require_once __DIR__ . '/../templates/header.php';
?>
<div class="container py-4">
  <h3 class="mb-3">Alertes relevé d'heures</h3>

  <?php if (empty($rows)): ?>
    <div class="alert alert-light border">Aucune alerte en cours.</div>
  <?php else: ?>
    <form method="post" class="mb-3">
      <button class="btn btn-sm btn-outline-primary">Tout marquer comme lu</button>
    </form>

    <div class="list-group">
      <?php foreach ($rows as $r): ?>
        <div class="list-group-item d-flex justify-content-between align-items-start <?= (int)$r['is_read'] ? '' : 'list-group-item-warning' ?>">
          <div class="me-3">
            <div class="<?= (int)$r['is_read'] ? 'text-muted' : 'fw-semibold' ?>">
              <?= nl2br(htmlspecialchars($r['message'] ?? '')) ?>
            </div>
            <small class="text-muted"><?= htmlspecialchars($r['created_at'] ?? '') ?></small>
          </div>

          <div class="d-flex gap-2">
            <?php if (!empty($r['stock_id'])): ?>
              <a class="btn btn-sm btn-outline-secondary"
                 href="/stock/article.php?id=<?= (int)$r['stock_id'] ?>" target="_blank">
                 Ouvrir
              </a>
            <?php endif; ?>
            <form method="post">
              <input type="hidden" name="notif_id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-success">Marquer lu</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
