<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';

if (empty($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? '') !== 'depot') {
    header("Location: /connexion.php"); exit;
}
$u      = $_SESSION['utilisateurs'];
$ENT_ID = (int)($u['entreprise_id'] ?? 0);

//
// Actions POST : marquer lu (unitaire / tout)
//
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (isset($_POST['mark_one'])) {
            $aid = (int)($_POST['alert_id'] ?? 0);
            if ($aid > 0) {
                // borne par entreprise via le join sur stock
                $sql = "
                    UPDATE stock_alerts a
                    JOIN stock s ON s.id = a.stock_id
                       SET a.is_read = 1
                     WHERE a.id = :id
                       AND a.archived_at IS NULL
                       AND (
                             a.type = 'hour_meter_request'
                          OR ( (a.type IS NULL OR a.type='generic') AND a.message LIKE 'Machine reçue sur chantier%')
                       )
                ";
                $params = [':id' => $aid];
                if ($ENT_ID > 0) { $sql .= " AND s.entreprise_id = :eid"; $params[':eid'] = $ENT_ID; }
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $_SESSION['success_message'] = "Alerte #$aid marquée comme lue.";
            }
        } elseif (isset($_POST['mark_all'])) {
            $sql = "
                UPDATE stock_alerts a
                JOIN stock s ON s.id = a.stock_id
                   SET a.is_read = 1
                 WHERE a.archived_at IS NULL
                   AND a.is_read = 0
                   AND (
                         a.type = 'hour_meter_request'
                      OR ( (a.type IS NULL OR a.type='generic') AND a.message LIKE 'Machine reçue sur chantier%')
                   )
            ";
            $params = [];
            if ($ENT_ID > 0) { $sql .= " AND s.entreprise_id = :eid"; $params[':eid'] = $ENT_ID; }
            $pdo->prepare($sql)->execute($params);
            $_SESSION['success_message'] = "Toutes les alertes ont été marquées comme lues.";
        }
    } catch (Throwable $e) {
        $_SESSION['error_message'] = "Impossible de marquer l’alerte : ".$e->getMessage();
    }
    header("Location: /stock/alerts_depot.php"); exit;
}

require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/navigation/navigation.php';

// Liste des alertes
$sql = "
  SELECT a.id, a.message, a.is_read, a.created_at,
         s.id AS stock_id, s.nom AS stock_nom
  FROM stock_alerts a
  JOIN stock s ON s.id = a.stock_id
  WHERE a.archived_at IS NULL
    AND (
          a.type = 'hour_meter_request'
       OR ( (a.type IS NULL OR a.type='generic') AND a.message LIKE 'Machine reçue sur chantier%')
    )
";
$params = [];
if ($ENT_ID>0) { $sql .= " AND s.entreprise_id = :eid"; $params[':eid']=$ENT_ID; }
$sql .= " ORDER BY a.is_read ASC, a.created_at DESC, a.id DESC ";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="container mt-4">

  <div class="d-flex align-items-center justify-content-between mb-3">
    <h2 class="mb-0">Alertes relevé d'heures (dépôt)</h2>
    <?php if (!empty($rows)): ?>
      <form method="post" class="m-0">
        <input type="hidden" name="mark_all" value="1">
        <button class="btn btn-outline-primary btn-sm">Tout marquer comme lu</button>
      </form>
    <?php endif; ?>
  </div>

  <?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success"><?= htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></div>
  <?php endif; ?>
  <?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></div>
  <?php endif; ?>

  <?php if (empty($rows)): ?>
    <div class="alert alert-light border">Aucune alerte en cours.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Article</th>
            <th style="min-width: 380px;">Message</th>
            <th>Créée le</th>
            <th>Lu ?</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <?php $unread = ((int)$r['is_read'] === 0); ?>
          <tr class="<?= $unread ? 'table-warning' : '' ?>">
            <td>#<?= (int)$r['id'] ?></td>
            <td><?= htmlspecialchars($r['stock_nom'] ?? 'Article') ?></td>
            <td><?= htmlspecialchars($r['message'] ?? '') ?></td>
            <td><?= $r['created_at'] ? date('d/m/Y H:i', strtotime($r['created_at'])) : '-' ?></td>
            <td>
              <?php if ($unread): ?>
                <span class="badge bg-danger">Non</span>
              <?php else: ?>
                <span class="badge bg-success">Oui</span>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <a class="btn btn-sm btn-primary"
                 target="_blank"
                 href="/stock/article.php?id=<?= (int)$r['stock_id'] ?>">Ouvrir</a>
              <?php if ($unread): ?>
                <form method="post" class="d-inline">
                  <input type="hidden" name="alert_id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-sm btn-outline-success" name="mark_one" value="1">Marquer lu</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
