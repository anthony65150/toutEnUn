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

// --- CSRF simple ---
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// =============== POST : marquer LU (unitaire) ou SUPPRIMER (unitaire) ===============
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action  = isset($_POST['action']) ? (string)$_POST['action'] : '';
    $markId  = isset($_POST['notif_id']) ? (int)$_POST['notif_id'] : 0;
    $csrfIn  = (string)($_POST['csrf'] ?? '');
    if (!hash_equals($csrf, $csrfIn)) {
        header('Location: /stock/alerts_chef.php'); exit;
    }

    try {
        if ($markId > 0) {
            // Récupération éventuelle du stock_id pour synchroniser côté admin (facultatif)
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

            if ($action === 'delete') {
                // Supprimer l'alerte de la liste côté chef/dépôt
                try {
                    $pdo->prepare("DELETE FROM notifications WHERE id = :id AND utilisateur_id = :uid")
                        ->execute([':id'=>$markId, ':uid'=>$uid]);
                } catch (Throwable $e) {}
            } else {
                // Marquer comme LU (fallback delete si pas de colonne is_read)
                try {
                    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id AND utilisateur_id = :uid")
                        ->execute([':id'=>$markId, ':uid'=>$uid]);
                } catch (Throwable $e) {
                    $pdo->prepare("DELETE FROM notifications WHERE id = :id AND utilisateur_id = :uid")
                        ->execute([':id'=>$markId, ':uid'=>$uid]);
                }
            }

            // SYNC admin (stock_alerts) : si on a un stock_id, on peut marquer LU les demandes HMR correspondantes
            if (!empty($row['stock_id'])) {
                $sql = "UPDATE stock_alerts a
                          JOIN stock s ON s.id = a.stock_id
                           SET a.is_read = 1
                        WHERE a.type = 'hour_meter_request'
                          AND a.archived_at IS NULL
                          AND a.stock_id = :sid";
                $p = [':sid' => (int)$row['stock_id']];
                if ($entId > 0) { $sql .= " AND s.entreprise_id = :eid"; $p[':eid'] = $entId; }
                try { $pdo->prepare($sql)->execute($p); } catch (Throwable $e) {}
            }
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
$pageTitle = "Alertes";
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/navigation/navigation.php';
?>
<div class="container py-4">

  <!-- Titre centré -->
  <div class="text-center mb-3">
    <h2 class="fw-bold m-0">Alertes</h2>
  </div>

  <?php if (empty($rows)): ?>
    <div class="alert alert-light border text-center">Aucune alerte en cours.</div>
  <?php else: ?>
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
                 href="/stock/article.php?id=<?= (int)$r['stock_id'] ?>">
                 Ouvrir
              </a>
            <?php endif; ?>

            <!-- Marquer comme lu -->
            <form method="post">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
              <input type="hidden" name="notif_id" value="<?= (int)$r['id'] ?>">
              <input type="hidden" name="action" value="mark">
              <button class="btn btn-sm btn-outline-success">Marquer lu</button>
            </form>

            <!-- Supprimer -->
            <form method="post" onsubmit="return confirm('Supprimer cette alerte ?');">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
              <input type="hidden" name="notif_id" value="<?= (int)$r['id'] ?>">
              <input type="hidden" name="action" value="delete">
              <button class="btn btn-sm btn-outline-danger">Supprimer</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
