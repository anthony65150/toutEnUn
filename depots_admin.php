<?php
require_once "./config/init.php";
require_once "templates/header.php";
require_once "templates/navigation/navigation.php";

// Vérifier si admin connecté
if (!isset($_SESSION['utilisateurs']) || $_SESSION['utilisateurs']['fonction'] !== 'administrateur') {
    header("Location: connexion.php");
    exit;
}

// Récupère tous les utilisateurs (ou filtre par rôle si besoin)
$utilisateurs = $pdo->query("
    SELECT id, prenom, nom, fonction 
    FROM utilisateurs 
    ORDER BY nom, prenom
")->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="container mt-4">
    <h1 class="mb-4 text-center">Gestion des dépôts</h1>

    <!-- Bouton création -->
    <div class="d-flex justify-content-center mb-3">
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#depotModal">
            + Créer un dépôt
        </button>
    </div>

    <!-- Tableau des dépôts -->
    <table class="table table-bordered text-center">
        <thead class="table-dark">
            <tr>
                <th>Nom</th>
                <th>Responsable</th>
                <th>Date de création</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="depotsTableBody">
            <?php
            // liste dépôts
            $depots = $pdo->query("SELECT * FROM depots ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC);

            foreach ($depots as $depot) {

                // responsable (utilisateur rôle depot)
                $resp = null;
                if (!empty($depot['responsable_id'])) {
                    $st = $pdo->prepare("SELECT prenom, nom FROM utilisateurs WHERE id = ?");
                    $st->execute([$depot['responsable_id']]);
                    $resp = $st->fetch(PDO::FETCH_ASSOC);
                }

                $respText = $resp ? htmlspecialchars($resp['prenom'].' '.$resp['nom']) : '—';

                $highlight = (isset($_GET['highlight']) && $_GET['highlight'] == $depot['id']) ? 'table-success' : '';
                echo "<tr class='align-middle $highlight'>";

                echo '<td><a class="text-decoration-none" href="stock_depot.php?depot_id='.(int)$depot['id'].'">' . htmlspecialchars($depot['nom']) . '</a></td>';
                echo '<td>' . $respText . '</td>';
                echo '<td>' . htmlspecialchars(date('d/m/Y', strtotime($depot['created_at']))) . '</td>';
                echo '<td>
                        <button class="btn btn-sm btn-warning edit-depot-btn"
                            data-bs-toggle="modal"
                            data-bs-target="#depotEditModal"
                            data-id="' . (int)$depot['id'] . '"
                            data-nom="' . htmlspecialchars($depot['nom']) . '"
                            data-resp="' . (int)($depot['responsable_id'] ?? 0) . '"
                            title="Modifier">
                            <i class="bi bi-pencil-fill"></i>
                        </button>

                        <button class="btn btn-sm btn-danger delete-depot-btn"
                            data-bs-toggle="modal"
                            data-bs-target="#deleteDepotModal"
                            data-id="' . (int)$depot['id'] . '"
                            title="Supprimer">
                            <i class="bi bi-trash-fill"></i>
                        </button>
                      </td>';
                echo '</tr>';
            }
            ?>
        </tbody>
    </table>
</div>

<!-- Modal création -->
<div class="modal fade" id="depotModal" tabindex="-1" aria-labelledby="depotModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="ajouterDepot.php" id="depotForm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="depotModalLabel">Créer un dépôt</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="depot_id" id="depotId">

                    <div class="mb-3">
                        <label for="depotNom" class="form-label">Nom du dépôt</label>
                        <input type="text" class="form-control" id="depotNom" name="nom" required>
                    </div>

                    <div class="mb-3">
                        <label for="depotResp" class="form-label">Responsable du dépôt</label>
                        <select class="form-select" id="depotResp" name="responsable_id" required>
                            <option value="">— Sélectionner un utilisateur —</option>
                            <?php foreach ($utilisateurs as $u): ?>
                                <option value="<?= (int)$u['id'] ?>">
                                    <?= htmlspecialchars($u['prenom'] . ' ' . $u['nom']) ?> 
                                    (<?= htmlspecialchars($u['fonction']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-success">Enregistrer</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                </div>
            </div>
        </form>
    </div>
</div>


<!-- Modal modification -->
<div class="modal fade" id="depotEditModal" tabindex="-1" aria-labelledby="depotEditModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="ajouterDepot.php" id="depotEditForm">
            <input type="hidden" name="depot_id" id="depotIdEdit">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="depotEditModalLabel">Modifier un dépôt</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="depotNomEdit" class="form-label">Nom du dépôt</label>
                        <input type="text" class="form-control" id="depotNomEdit" name="nom" required>
                    </div>
                    <div class="mb-3">
                        <label for="depotRespEdit" class="form-label">Responsable</label>
                        <select class="form-select" id="depotRespEdit" name="responsable_id">
                            <option value="">— Aucun —</option>
                            <?php
                            foreach ($resps as $r) {
                                echo '<option value="' . (int)$r['id'] . '">' . htmlspecialchars($r['prenom'] . ' ' . $r['nom']) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-success">Enregistrer</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal confirmation suppression -->
<div class="modal fade" id="deleteDepotModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="supprimerDepot.php" id="deleteDepotForm">
            <input type="hidden" name="delete_id" id="deleteDepotId">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirmer la suppression</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    Es-tu sûr de vouloir supprimer ce dépôt ? Cette action est irréversible.
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-danger">Supprimer</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Toast succès -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1055">
    <div id="depotToast" class="toast align-items-center text-white bg-success border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="depotToastMsg">
                Dépôt enregistré avec succès.
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<?php if (isset($_GET['success'])): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const msgType = "<?php echo $_GET['success']; ?>";
    let message = "Dépôt enregistré avec succès.";
    if (msgType === "create") message = "Dépôt créé avec succès.";
    else if (msgType === "update") message = "Dépôt modifié avec succès.";
    else if (msgType === "delete") message = "Dépôt supprimé avec succès.";

    const toastEl = document.getElementById('depotToast');
    const toastMsg = document.getElementById('depotToastMsg');
    toastMsg.textContent = message;
    new bootstrap.Toast(toastEl).show();
});
</script>
<?php endif; ?>

<script src="/js/depots_admin.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const highlightedRow = document.querySelector('tr.table-success');
    if (highlightedRow) {
        setTimeout(() => { highlightedRow.classList.remove('table-success'); }, 3000);
    }
});
</script>

<?php require_once "templates/footer.php"; ?>
