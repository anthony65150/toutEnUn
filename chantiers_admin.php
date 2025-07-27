<?php
require_once "./config/init.php";
require_once "templates/header.php";
require_once "templates/navigation/navigation.php";

// Vérifier si admin connecté
if (!isset($_SESSION['utilisateurs']) || $_SESSION['utilisateurs']['fonction'] !== 'administrateur') {
    header("Location: connexion.php");
    exit;
}
?>

<div class="container mt-4">
    <h1 class="mb-4 text-center">Gestion des chantiers</h1>

    <!-- Bouton création -->
    <div class="d-flex justify-content-center mb-3">
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#chantierModal">
            + Créer un chantier
        </button>
    </div>
    <!-- Tableau des chantiers -->
    <table class="table table-bordered text-center">
        <thead class="table-dark">
            <tr>
                <th>Nom</th>
                <th>Chef assigné</th>
                <th>Date début</th>
                <th>Date fin</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="chantiersTableBody">
            <?php
            $chantiers = $pdo->query("SELECT * FROM chantiers")->fetchAll(PDO::FETCH_ASSOC);

            foreach ($chantiers as $chantier) {
                $stmt = $pdo->prepare("
                SELECT u.id, u.prenom, u.nom 
                FROM utilisateurs u
                INNER JOIN utilisateur_chantiers uc ON uc.utilisateur_id = u.id
                WHERE uc.chantier_id = ?
            ");
                $stmt->execute([$chantier['id']]);
                $chefs = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $chefsText = implode(', ', array_map(fn($c) => htmlspecialchars($c['prenom'] . ' ' . $c['nom']), $chefs));

                $highlight = (isset($_GET['highlight']) && $_GET['highlight'] == $chantier['id']) ? 'table-success' : '';
                echo "<tr class='align-middle $highlight'>";

                echo '<td>' . htmlspecialchars($chantier['nom']) . '</td>';
                echo '<td>' . $chefsText . '</td>';
                echo '<td>' . htmlspecialchars($chantier['date_debut']) . '</td>';
                echo '<td>' . htmlspecialchars($chantier['date_fin']) . '</td>';
                echo '<td>
              <button class="btn btn-sm btn-warning edit-btn"
    data-bs-toggle="modal"
    data-bs-target="#chantierEditModal"
    data-id="' . $chantier['id'] . '"
    data-nom="' . htmlspecialchars($chantier['nom']) . '"
    data-description="' . htmlspecialchars($chantier['description']) . '"
    data-debut="' . $chantier['date_debut'] . '"
    data-fin="' . $chantier['date_fin'] . '"
    data-chef="' . ($chefs[0]['id'] ?? '') . '"
    title="Modifier"
>
    <i class="bi bi-pencil-fill"></i>
</button>

<button class="btn btn-sm btn-danger delete-btn"
    data-bs-toggle="modal"
    data-bs-target="#deleteModal"
    data-id="' . $chantier['id'] . '"
    title="Supprimer"
>
    <i class="bi bi-trash-fill"></i>
</button>

            </td>';
            }
            ?>
        </tbody>
    </table>

</div>


<!-- Modal création / modification -->
<div class="modal fade" id="chantierModal" tabindex="-1" aria-labelledby="chantierModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="ajouterChantier.php" id="chantierForm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="chantierModalLabel">Créer un chantier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="chantier_id" id="chantierId">
                    <div class="mb-3">
                        <label for="chantierNom" class="form-label">Nom du chantier</label>
                        <input type="text" class="form-control" id="chantierNom" name="nom" required>
                    </div>
                    <div class="mb-3">
                        <label for="chantierDesc" class="form-label">Description</label>
                        <textarea class="form-control" id="chantierDesc" name="description"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="chantierDebut" class="form-label">Date de début</label>
                        <input type="date" class="form-control" id="chantierDebut" name="date_debut">
                    </div>
                    <div class="mb-3">
                        <label for="chantierFin" class="form-label">Date de fin</label>
                        <input type="date" class="form-control" id="chantierFin" name="date_fin">
                    </div>

                    <div class="mb-3">
                        <label for="chefChantier" class="form-label">Chef(s) de chantier</label>
                        <select class="form-select" id="chefChantier" name="responsable_id" required>

                            <?php
                            $chefs = $pdo->query("SELECT id, prenom, nom FROM utilisateurs WHERE fonction = 'chef'")->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($chefs as $chef) {
                                echo '<option value="' . $chef['id'] . '">' . htmlspecialchars($chef['prenom'] . ' ' . $chef['nom']) . '</option>';
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

<!-- Modal modification -->
<div class="modal fade" id="chantierEditModal" tabindex="-1" aria-labelledby="chantierEditModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="ajouterChantier.php" id="chantierEditForm">
            <input type="hidden" name="chantier_id" id="chantierIdEdit">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="chantierEditModalLabel">Modifier un chantier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="chantierNomEdit" class="form-label">Nom du chantier</label>
                        <input type="text" class="form-control" id="chantierNomEdit" name="nom" required>
                    </div>
                    <div class="mb-3">
                        <label for="chantierDescEdit" class="form-label">Description</label>
                        <textarea class="form-control" id="chantierDescEdit" name="description"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="chantierDebutEdit" class="form-label">Date de début</label>
                        <input type="date" class="form-control" id="chantierDebutEdit" name="date_debut">
                    </div>
                    <div class="mb-3">
                        <label for="chantierFinEdit" class="form-label">Date de fin</label>
                        <input type="date" class="form-control" id="chantierFinEdit" name="date_fin">
                    </div>
                    <div class="mb-3">
                        <label for="chefChantierEdit" class="form-label">Chef(s) de chantier</label>
                        <select class="form-select" id="chefChantierEdit" name="responsable_id" required>

                            <?php
                            foreach ($chefs as $chef) {
                                echo '<option value="' . $chef['id'] . '">' . htmlspecialchars($chef['prenom'] . ' ' . $chef['nom']) . '</option>';
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
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="supprimerChantier.php" id="deleteForm">
            <input type="hidden" name="delete_id" id="deleteId">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirmer la suppression</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    Es-tu sûr de vouloir supprimer ce chantier ? Cette action est irréversible.
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-danger">Supprimer</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1055">
    <div id="chantierToast" class="toast align-items-center text-white bg-success border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="chantierToastMsg">
                Chantier enregistré avec succès.
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Fermer"></button>
        </div>
    </div>
</div>
<?php if (isset($_GET['success'])): ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const type = "<?php echo $_GET['success']; ?>";
            let message = "Chantier enregistré avec succès.";
            if (type === "create") message = "Chantier créé avec succès.";
            else if (type === "update") message = "Chantier modifié avec succès.";
            else if (type === "delete") message = "Chantier supprimé avec succès.";

            showChantierToast(message);
        });
    </script>
<?php endif; ?>


<script src="/js/chantiers_admin.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const highlightedRow = document.querySelector('tr.table-success');
        if (highlightedRow) {
            setTimeout(() => {
                highlightedRow.classList.remove('table-success');
            }, 3000); // 3000 ms = 3 secondes
        }
    });
</script>


<?php require_once "templates/footer.php"; ?>