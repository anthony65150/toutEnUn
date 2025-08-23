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

    <!-- Bouton création centré -->
    <div class="d-flex justify-content-center mb-3">
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalDepotCreate">
            + Créer un dépôt
        </button>
    </div>

    <!-- Barre de recherche -->
    <input
        type="text"
        id="depotSearchInput"
        class="form-control mb-4"
        placeholder="Rechercher un dépôt...">

    <!-- Tableau des dépôts -->
    <table class="table table-striped table-bordered text-center">
        <thead class="table-dark">
            <tr>
                <th>Nom</th>
                <th>Responsable</th>
                <th>Date de création</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="depotsTbody">
            <?php
            $depots = $pdo->query("SELECT * FROM depots ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($depots as $depot) {
                $resp = null;
                if (!empty($depot['responsable_id'])) {
                    $st = $pdo->prepare("SELECT prenom, nom FROM utilisateurs WHERE id = ?");
                    $st->execute([$depot['responsable_id']]);
                    $resp = $st->fetch(PDO::FETCH_ASSOC);
                }
                $respText = $resp ? htmlspecialchars($resp['prenom'] . ' ' . $resp['nom']) : '—';
                $highlight = (isset($_GET['highlight']) && $_GET['highlight'] == $depot['id']) ? 'table-success' : '';
                echo "<tr class='align-middle $highlight'>";

                echo '<td>
                <a class="link-primary fw-semibold text-decoration-none" href="depot_contenu.php?depot_id=' . (int)$depot['id'] . '">'
                    . htmlspecialchars($depot['nom']) .
                    '</a>
              </td>';

                echo '<td>' . $respText . '</td>';
                echo '<td>' . htmlspecialchars(date('d/m/Y', strtotime($depot['created_at']))) . '</td>';
                echo '<td>
                <button class="btn btn-sm btn-warning edit-depot-btn"
                        data-bs-toggle="modal"
                        data-bs-target="#modalDepotEdit"
                        data-id="' . (int)$depot['id'] . '"
                        data-nom="' . htmlspecialchars($depot['nom'], ENT_QUOTES) . '"
                        data-resp="' . (int)($depot['responsable_id'] ?? 0) . '"
                        title="Modifier">
                  <i class="bi bi-pencil-fill"></i>
                </button>
                <button class="btn btn-sm btn-danger delete-depot-btn"
                        data-bs-toggle="modal"
                        data-bs-target="#modalDepotDelete"
                        data-id="' . (int)$depot['id'] . '"
                        title="Supprimer">
                  <i class="bi bi-trash-fill"></i>
                </button>
              </td>';

                echo '</tr>';
            }
            ?>
            <!-- ligne "aucun résultat" (affichée par JS si besoin) -->
            <tr id="noResultsRow" class="d-none">
                <td colspan="4" class="text-muted text-center py-4">Aucun dépôt trouvé</td>
            </tr>
        </tbody>
    </table>

</div>

<!-- Modal création -->
<div class="modal fade" id="modalDepotCreate" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form id="formDepotCreate"> <!-- au lieu de depotForm -->
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Créer un dépôt</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nom du dépôt</label>
                        <input type="text" class="form-control" name="nom" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Responsable</label>
                        <select class="form-select" name="responsable_id">
                            <option value="">— Aucun —</option>
                            <?php foreach ($utilisateurs as $u): ?>
                                <option value="<?= (int)$u['id'] ?>">
                                    <?= htmlspecialchars($u['prenom'] . ' ' . $u['nom']) ?> (<?= htmlspecialchars($u['fonction']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-success" type="submit">Enregistrer</button>
                    <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Annuler</button>
                </div>
            </div>
        </form>
    </div>
</div>



<!-- Modal modification -->
<div class="modal fade" id="modalDepotEdit" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form id="formDepotEdit">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Modifier un dépôt</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="editDepotId" name="id"> <!-- IMPORTANT: name="id" -->
                    <div class="mb-3">
                        <label class="form-label">Nom du dépôt</label>
                        <input type="text" class="form-control" id="editNom" name="nom" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Responsable</label>
                        <select class="form-select" id="editResp" name="responsable_id">
                            <option value="">— Aucun —</option>
                            <?php foreach ($utilisateurs as $u): ?>
                                <option value="<?= (int)$u['id'] ?>">
                                    <?= htmlspecialchars($u['prenom'] . ' ' . $u['nom']) ?> (<?= htmlspecialchars($u['fonction']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-success" type="submit">Enregistrer</button>
                    <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Annuler</button>
                </div>
            </div>
        </form>
    </div>
</div>


<!-- Modal confirmation suppression -->
<div class="modal fade" id="modalDepotDelete" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form id="formDepotDelete">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirmer la suppression</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">Supprimer ce dépôt ?</div>
                <div class="modal-footer">
                    <input type="hidden" id="deleteDepotId" name="id"> <!-- IMPORTANT: name="id" -->
                    <button class="btn btn-danger" type="submit">Supprimer</button>
                    <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Annuler</button>
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

<script src="/js/depotsGestion_admin.js?v=3"></script>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const highlightedRow = document.querySelector('tr.table-success');
        if (highlightedRow) {
            setTimeout(() => {
                highlightedRow.classList.remove('table-success');
            }, 3000);
        }
    });
</script>

<?php require_once "templates/footer.php"; ?>