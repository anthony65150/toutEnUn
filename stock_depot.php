<?php
require_once "./config/init.php";
require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/navigation/navigation.php';

if (!isset($_SESSION['utilisateurs']) || $_SESSION['utilisateurs']['fonction'] !== 'depot') {
  header('Location: ../index.php');
  exit;
}

// Récupérer l'id du dépôt lié à l'utilisateur connecté
$userId = $_SESSION['utilisateurs']['id'];
$stmtDepot = $pdo->prepare("SELECT id FROM depots WHERE responsable_id = ?");
$stmtDepot->execute([$userId]);
$depot = $stmtDepot->fetch(PDO::FETCH_ASSOC);
$depotId = $depot ? (int)$depot['id'] : null;
if (!$depotId) {
  die("Dépôt non trouvé pour cet utilisateur.");
}

// Récupérer les associations stock ↔ chantiers
$stmt = $pdo->query("SELECT sc.stock_id, c.nom AS chantier_nom, sc.quantite FROM stock_chantiers sc JOIN chantiers c ON sc.chantier_id = c.id");
$chantierAssoc = [];
foreach ($stmt as $row) {
  $chantierAssoc[$row['stock_id']][] = [
    'nom' => $row['chantier_nom'],
    'quantite' => $row['quantite']
  ];
}

// Récupérer le stock total recalculé (dépôt + chantiers)
$stmt = $pdo->prepare("
    SELECT 
        s.id, 
        s.nom, 
        COALESCE(sd.quantite, 0) + COALESCE(sc.total_chantier, 0) AS total_recalculé,
        COALESCE(sd.quantite, 0) AS quantite_stock_depot,
        COALESCE(sd.quantite, 0) - COALESCE((
            SELECT SUM(te.quantite) 
            FROM transferts_en_attente te 
            WHERE te.article_id = s.id 
              AND te.source_type = 'depot' 
              AND te.source_id = ?
              AND te.statut = 'en_attente'
        ), 0) AS disponible_depot,
        s.categorie, 
        s.sous_categorie
    FROM stock s
    LEFT JOIN stock_depots sd ON s.id = sd.stock_id AND sd.depot_id = ?
    LEFT JOIN (
        SELECT stock_id, SUM(quantite) AS total_chantier 
        FROM stock_chantiers 
        GROUP BY stock_id
    ) sc ON s.id = sc.stock_id
    ORDER BY s.nom
");
$stmt->execute([$depotId, $depotId]);
$stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer liste des chantiers
$chantiers = $pdo->query("SELECT id, nom FROM chantiers ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les transferts en attente vers ce dépôt
$stmt = $pdo->prepare("
    SELECT t.id AS transfert_id, s.nom AS article_nom, t.quantite, u.nom AS demandeur_nom, u.prenom AS demandeur_prenom
    FROM transferts_en_attente t
    JOIN stock s ON t.article_id = s.id
    JOIN utilisateurs u ON t.demandeur_id = u.id
    WHERE t.destination_type = 'depot' AND t.destination_id = ? AND t.statut = 'en_attente'
");
$stmt->execute([$depotId]);
$transfertsEnAttente = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container py-5">
  <h2 class="text-center mb-5">Stock dépôt</h2>

  <!-- Tableau stock -->
  <div class="table-responsive">
    <table class="table table-bordered table-hover align-middle text-center">
      <thead class="table-dark">
        <tr>
          <th>Nom</th>
          <th>Photo</th>
          <th>Disponible total</th>
          <th>Disponible dépôt</th>
          <th>Chantiers</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($stocks as $stock):
          $stockId = $stock['id'];
          $total = (int)$stock['total_recalculé'];
          $dispoDepot = (int)$stock['disponible_depot'];
          $nom = htmlspecialchars($stock['nom']);
          $chantierList = $chantierAssoc[$stockId] ?? [];
        ?>
          <tr>
            <td><?= "$nom ($total)" ?></td>
            <td>
              <img src="uploads/photos/<?= $stockId ?>.jpg" alt="<?= $nom ?>" style="height: 40px;">
            </td>
            <td>
              <span class="badge bg-primary">
                <?= $total ?>
              </span>
            </td>
            <td>
              <span class="badge quantite-disponible <?= $dispoDepot < 10 ? 'bg-danger' : 'bg-success' ?>">
                <?= $dispoDepot ?>
              </span>
            </td>
            <td>
              <?php if (count($chantierList)): ?>
                <?php foreach ($chantierList as $chantier): ?>
                  <div><?= htmlspecialchars($chantier['nom']) ?> (<?= (int)$chantier['quantite'] ?>)</div>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="text-muted">Aucun</span>
              <?php endif; ?>
            </td>
            <td>
              <button class="btn btn-sm btn-primary transfer-btn" data-stock-id="<?= $stockId ?>" data-stock-nom="<?= $nom ?>">
                <i class="bi bi-arrow-left-right"></i> Transférer
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Section transferts à valider -->
  <h3 class="mt-5">Transferts à valider</h3>
  <?php if ($transfertsEnAttente): ?>
    <div class="table-responsive">
      <table class="table table-bordered align-middle text-center">
        <thead class="table-info">
          <tr>
            <th>Article</th>
            <th>Quantité</th>
            <th>Envoyé par</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($transfertsEnAttente as $t): ?>
            <tr>
              <td><?= htmlspecialchars($t['article_nom']) ?></td>
              <td><?= $t['quantite'] ?></td>
              <td><?= htmlspecialchars($t['demandeur_prenom'] . ' ' . $t['demandeur_nom']) ?></td>
              <td>
                <form method="post" action="validerReception_depot.php" style="display:inline;">
                  <input type="hidden" name="transfert_id" value="<?= $t['transfert_id'] ?>">
                  <button type="submit" class="btn btn-success btn-sm">
                      ✅ Valider réception
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="text-muted">Aucun transfert en attente de validation.</p>
  <?php endif; ?>
</div>

<!-- MODAL TRANSFERT -->
<div class="modal fade" id="transferModal" tabindex="-1" aria-labelledby="transferModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title" id="transferModalLabel">Transfert vers un chantier</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>

      <div class="modal-body">
        <form id="transferForm">
          <input type="hidden" id="modalStockId">

          <div class="mb-3">
            <label for="destination" class="form-label">Destination (chantier)</label>
            <select class="form-select" id="destination" required>
              <option value="" disabled selected>Choisir le chantier</option>
              <?php foreach ($chantiers as $chantier): ?>
                <option value="<?= $chantier['id'] ?>"><?= htmlspecialchars($chantier['nom']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label for="transferQty" class="form-label">Quantité à transférer</label>
            <input type="number" min="1" class="form-control" id="transferQty" required>
          </div>
        </form>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
        <button type="submit" class="btn btn-primary" id="confirmTransfer">OK</button>
      </div>

    </div>
  </div>
</div>

<script src="/js/stockGestion_depot.js"></script>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
