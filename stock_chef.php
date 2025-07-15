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

// Récupère stock_depots pour additionner plus tard
$stmt = $pdo->query("
    SELECT stock_id, SUM(quantite) AS quantite_depot
    FROM stock_depots
    GROUP BY stock_id
");
$depotAssoc = [];
foreach ($stmt as $row) {
    $depotAssoc[$row['stock_id']] = (int)$row['quantite_depot'];
}

// Récupérer stock global (sans quantite_totale ni quantite_disponible)
$stmt = $pdo->query("SELECT id, nom, categorie, sous_categorie FROM stock ORDER BY nom");
$stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer dépôts et chantiers (hors chantier du chef) pour sélection destination
$depots = $pdo->query("SELECT id, nom FROM depots ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$chantiers = $pdo->query("SELECT id, nom FROM chantiers ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les transferts en attente pour ce chantier
$stmt = $pdo->prepare("
    SELECT t.id AS transfert_id, s.nom AS article_nom, t.quantite, u.nom AS demandeur_nom, u.prenom AS demandeur_prenom
    FROM transferts_en_attente t
    JOIN stock s ON t.article_id = s.id
    JOIN utilisateurs u ON t.demandeur_id = u.id
    WHERE t.destination_type = 'chantier' AND t.destination_id = ? AND t.statut = 'en_attente'
");
$stmt->execute([$utilisateurChantierId]);
$transfertsEnAttente = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container py-5">
    <h2 class="text-center mb-5">Stock</h2>
    <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle">
            <thead class="table-dark text-center">
                <tr>
                    <th>Nom</th>
                    <th>Photo</th>
                    <th>Quantité totale</th>
                    <th>Mon chantier</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stocks as $stock): ?>
                    <?php
                    $stockId = $stock['id'];
                    $nom = htmlspecialchars($stock['nom']);

                    $quantiteDepot = $depotAssoc[$stockId] ?? 0;
                    $quantiteChantiers = 0;
                    $quantiteMonChantier = 0;

                    if (!empty($chantierAssoc[$stockId])) {
                        foreach ($chantierAssoc[$stockId] as $chantier) {
                            $quantiteChantiers += (int)$chantier['quantite'];
                            if ($chantier['id'] == $utilisateurChantierId) {
                                $quantiteMonChantier = (int)$chantier['quantite'];
                            }
                        }
                    }

                    $quantiteTotale = $quantiteDepot + $quantiteChantiers;
                    ?>
                    <tr>
                        <td><?= $nom ?> (<?= $quantiteTotale ?>)</td>
                        <td class="text-center"><img src="uploads/photos/<?= $stockId ?>.jpg" style="height: 40px;" /></td>
                        <td class="text-center">
                            <span class="badge bg-primary"><?= $quantiteDepot ?> dépôt</span>
                            <span class="badge bg-warning text-dark"><?= $quantiteChantiers ?> chantiers</span>
                        </td>
                        <td class="text-center"><?= $quantiteMonChantier ?></td>
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

    <!-- Section transferts à valider -->
    <h3 class="mt-5">Transferts à valider</h3>
    <?php if ($transfertsEnAttente): ?>
        <table class="table table-bordered">
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
<form method="post" action="validerReception_chef.php" style="display:inline;">
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
    <?php else: ?>
        <p class="text-muted">Aucun transfert en attente de validation.</p>
    <?php endif; ?>
</div>

<!-- MODAL TRANSFERT -->
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
              <optgroup label="Dépôts">
                <?php foreach ($depots as $depot): ?>
                  <option value="depot_<?= $depot['id'] ?>"><?= htmlspecialchars($depot['nom']) ?></option>
                <?php endforeach; ?>
              </optgroup>
              <optgroup label="Chantiers">
                <?php foreach ($chantiers as $chantier):
                    if ($chantier['id'] == $utilisateurChantierId) continue; ?>
                  <option value="chantier_<?= $chantier['id'] ?>"><?= htmlspecialchars($chantier['nom']) ?></option>
                <?php endforeach; ?>
              </optgroup>
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

<script src="/js/stockGestion_chef.js"></script>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
