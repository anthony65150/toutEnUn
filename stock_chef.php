<?php
require_once "./config/init.php";
require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/navigation/navigation.php';

if (!isset($_SESSION['utilisateurs'])) {
    header("Location: connexion.php");
    exit;
}

$utilisateurChantierId = $_SESSION['utilisateurs']['chantier_id'] ?? null;

// Récupère les associations stock ↔ chantiers
$stmt = $pdo->query("
    SELECT sc.stock_id, c.id AS chantier_id, c.nom AS chantier_nom, sc.quantite
    FROM stock_chantiers sc
    JOIN chantiers c ON sc.chantier_id = c.id
");
$chantierAssoc = [];
foreach ($stmt as $row) {
    $chantierAssoc[$row['stock_id']][] = [
        'id' => $row['chantier_id'],
        'nom' => $row['chantier_nom'],
        'quantite' => $row['quantite']
    ];
}

$stmt = $pdo->query("SELECT id, nom, quantite_totale AS total, quantite_disponible AS disponible, categorie, sous_categorie FROM stock ORDER BY nom");
$stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container py-5">
    <h2 class="text-center mb-5">Stock</h2>
    <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle">
            <thead class="table-dark text-center">
                <tr>
                    <th>Nom</th>
                    <th>Photo</th>
                    <th>Quantité</th>
                    <th>Mon chantier</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stocks as $stock): ?>
                    <?php
                    $stockId = $stock['id'];
                    $nom = htmlspecialchars($stock['nom']);
                    $total = (int)$stock['total'];
                    $dispo = (int)$stock['disponible'];
                    $surChantier = $total - $dispo;
                    $quantite = 0;

                    if (!empty($chantierAssoc[$stockId])) {
                        foreach ($chantierAssoc[$stockId] as $chantier) {
                            if ($chantier['id'] == $utilisateurChantierId) {
                                $quantite = (int)$chantier['quantite'];
                                break;
                            }
                        }
                    }
                    ?>
                    <tr>
                        <td><?= $nom ?> (<?= $total ?>)</td>
                        <td class="text-center"><img src="uploads/photos/<?= $stockId ?>.jpg" style="height: 40px;" /></td>
                        <td class="text-center quantite-col">
                            <span class="badge bg-success"><?= $dispo ?> dispo</span>
                            <span class="badge bg-warning text-dark"><?= $surChantier ?> sur chantier</span>
                        </td>
                        <td class="text-center chantier-col" data-stock-id="<?= $stockId ?>" data-chantier-id="<?= $utilisateurChantierId ?>">
                            <?= $quantite ?>
                        </td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-primary transfer-btn" data-stock-id="<?= $stockId ?>" data-stock-nom="<?= $nom ?>">
                                <i class="bi bi-arrow-left-right"></i> Transférer
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="transferModal" tabindex="-1" aria-labelledby="transferModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="transferModalLabel">Transfert d'article</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <form id="transferForm">
          <input type="hidden" id="modalStockId">
          <div class="mb-3">
            <label for="destination" class="form-label">Destination</label>
            <select class="form-select" id="destination" required>
              <option value="" disabled selected>Choisir la destination</option>
              <option value="depot">Dépôt</option>
              <?php
              $chantiers = $pdo->query("SELECT id, nom FROM chantiers ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
              foreach ($chantiers as $chantier):
                  if ($chantier['id'] == $utilisateurChantierId) continue;
              ?>
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

<!-- Toast Bootstrap pour confirmation -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 9999">
  <div id="transferToast" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body">
        ✅ Transfert effectué avec succès !
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Fermer"></button>
    </div>
  </div>
</div>

<!-- Toast d’erreur -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 9999">
  <div id="errorToast" class="toast align-items-center text-bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body" id="errorToastMessage">
        ❌ Une erreur est survenue.
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Fermer"></button>
    </div>
  </div>
</div>


<script src="/js/stockGestion_chef.js"></script>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
