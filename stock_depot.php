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

// Récupérer les associations stock ↔ chantiers (quantité sur chantier)
$stmt = $pdo->query("SELECT sc.stock_id, c.nom AS chantier_nom, sc.quantite FROM stock_chantiers sc JOIN chantiers c ON sc.chantier_id = c.id");
$chantierAssoc = [];
foreach ($stmt as $row) {
    $chantierAssoc[$row['stock_id']][] = [
        'nom' => $row['chantier_nom'],
        'quantite' => $row['quantite']
    ];
}

// Récupérer le stock total et dispo pour le dépôt courant (d'après depot_id)
$stmt = $pdo->prepare("
    SELECT 
        s.id, 
        s.nom, 
        s.quantite_totale AS total, 
        sd.quantite AS quantite_stock_depot,
        COALESCE(sd.quantite, 0) - COALESCE((
            SELECT SUM(te.quantite) 
            FROM transferts_en_attente te 
            WHERE te.article_id = s.id 
              AND te.source_type = 'depot' 
              AND te.source_id = ? 
              AND te.statut = 'en_attente'
        ), 0) AS disponible,
        s.categorie, 
        s.sous_categorie
    FROM stock s
    LEFT JOIN stock_depots sd ON s.id = sd.stock_id AND sd.depot_id = ?
    ORDER BY s.nom
");
$stmt->execute([$depotId, $depotId]);
$stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer liste des chantiers pour sélection destination transfert
$chantiers = $pdo->query("SELECT id, nom FROM chantiers ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container py-5">
  <h2 class="text-center mb-5">Stock dépôt</h2>
  <div class="table-responsive">
    <table class="table table-bordered table-hover align-middle text-center">
      <thead class="table-dark">
        <tr>
          <th>Nom</th>
          <th>Photo</th>
          <th>Disponible</th>
          <th>Chantiers</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($stocks as $stock):
          $stockId = $stock['id'];
          $total = (int)$stock['total'];
          $dispo = (int)$stock['disponible'];
          $nom = htmlspecialchars($stock['nom']);
          $chantierList = $chantierAssoc[$stockId] ?? [];
        ?>
          <tr>
            <td><?= "$nom ($total)" ?></td>
            <td>
              <img src="uploads/photos/<?= $stockId ?>.jpg" alt="<?= $nom ?>" style="height: 40px;">
            </td>
            <td>
              <span class="badge quantite-disponible <?= $dispo < 10 ? 'bg-danger' : 'bg-success' ?>">
                <?= $dispo ?>
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
              <button
                class="btn btn-sm btn-primary transfer-btn"
                data-stock-id="<?= $stockId ?>"
                data-stock-nom="<?= $nom ?>">
                <i class="bi bi-arrow-left-right"></i> Transférer
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal Transfert vers chantier -->
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

<!-- Toasts -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 9999">
  <div id="transferToast" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body">✅ Transfert effectué avec succès !</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Fermer"></button>
    </div>
  </div>
  <div id="errorToast" class="toast align-items-center text-bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body" id="errorToastMessage">❌ Une erreur est survenue.</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Fermer"></button>
    </div>
  </div>
</div>

<script src="/js/stockGestion_depot.js"></script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>