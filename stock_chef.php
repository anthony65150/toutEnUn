<?php
require_once "./config/init.php";
require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/navigation/navigation.php';

if (!isset($_SESSION['utilisateurs'])) {
    header("Location: connexion.php");
    exit;
}

$utilisateurChantierId = $_SESSION['utilisateurs']['chantier_id'] ?? null;

// Associations stock ↔ chantiers
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

// Dépôts
$stmt = $pdo->query("
    SELECT sd.stock_id, d.id AS depot_id, d.nom AS depot_nom, sd.quantite
    FROM stock_depots sd
    JOIN depots d ON sd.depot_id = d.id
");
$depotAssocDetail = [];
$depotAssocSum = [];
foreach ($stmt as $row) {
    $depotAssocDetail[$row['stock_id']][] = [
        'id' => $row['depot_id'],
        'nom' => $row['depot_nom'],
        'quantite' => $row['quantite']
    ];
    $depotAssocSum[$row['stock_id']] = ($depotAssocSum[$row['stock_id']] ?? 0) + (int)$row['quantite'];
}

// Stock global organisé par catégorie/sous-catégorie
$stmt = $pdo->query("
    SELECT id, nom, categorie, sous_categorie
    FROM stock
    ORDER BY categorie, sous_categorie, nom
");
$stocksOrganized = [];
foreach ($stmt as $stock) {
    $stocksOrganized[$stock['categorie']][$stock['sous_categorie']][] = $stock;
}

// Dépôts et chantiers pour modal
$depots = $pdo->query("SELECT id, nom FROM depots ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$chantiers = $pdo->query("SELECT id, nom FROM chantiers ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

// Transferts en attente
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
    <h2 class="text-center mb-5">Stock - Chef de chantier</h2>

    <?php foreach ($stocksOrganized as $categorie => $sousCats): ?>
        <h3 class="mt-4">🔷 Catégorie : <?= htmlspecialchars($categorie) ?></h3>
        <?php foreach ($sousCats as $sousCat => $articles): ?>
            <h5 class="mt-3">🔹 Sous-catégorie : <?= htmlspecialchars($sousCat) ?></h5>
            <div class="table-responsive mb-4">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-dark text-center">
                        <tr>
                            <th>Nom (Total)</th>
                            <th>Photo</th>
                            <th>Dépôts</th>
                            <th>Chantiers</th>
                            <th>Mon chantier</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($articles as $stock): ?>
                            <?php
                            $stockId = $stock['id'];
                            $nom = htmlspecialchars($stock['nom']);

                            $quantiteDepot = $depotAssocSum[$stockId] ?? 0;
                            $quantiteChantiers = 0;
                            $quantiteMonChantier = 0;

                            $depotsList = '';
                            if (!empty($depotAssocDetail[$stockId])) {
                                foreach ($depotAssocDetail[$stockId] as $d) {
                                    $depotsList .= $d['nom'] . ' (' . $d['quantite'] . ')<br>';
                                }
                            }

                            $chantiersList = '';
                            if (!empty($chantierAssoc[$stockId])) {
                                foreach ($chantierAssoc[$stockId] as $c) {
                                    $quantiteChantiers += (int)$c['quantite'];
                                    $chantiersList .= $c['nom'] . ' (' . $c['quantite'] . ')<br>';
                                    if ($c['id'] == $utilisateurChantierId) {
                                        $quantiteMonChantier = (int)$c['quantite'];
                                    }
                                }
                            }

                            $quantiteTotale = $quantiteDepot + $quantiteChantiers;
                            $badge = $quantiteMonChantier > 0
                                ? '<span class="badge bg-success">' . $quantiteMonChantier . '</span>'
                                : '<span class="badge bg-danger">0</span>';
                            ?>
                            <tr>
                                <td><?= $nom ?> (<?= $quantiteTotale ?>)</td>
                                <td class="text-center"><img src="uploads/photos/<?= $stockId ?>.jpg" style="height: 40px;" /></td>
                                <td><?= $depotsList ?: '<span class="text-muted">-</span>' ?></td>
                                <td><?= $chantiersList ?: '<span class="text-muted">-</span>' ?></td>
                                <td class="text-center"><?= $badge ?></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-primary transfer-btn"
                                        data-stock-id="<?= $stockId ?>"
                                        data-stock-nom="<?= $nom ?>"
                                        data-quantite="<?= $quantiteMonChantier ?>">
                                        <i class="bi bi-arrow-left-right"></i> Transférer
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>

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
