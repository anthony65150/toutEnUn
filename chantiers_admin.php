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
    <h1 class="mb-4">Gestion des chantiers</h1>

    <!-- Bouton création -->
    <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#chantierModal">
        + Créer un chantier
    </button>

    <!-- Tableau des chantiers -->
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Nom</th>
                <th>Chef assigné</th>
                <th>Date début</th>
                <th>Date fin</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="chantiersTableBody">
            <!-- À remplir dynamiquement en PHP -->
        </tbody>
    </table>
</div>

<!-- Modal création / modification -->
<div class="modal fade" id="chantierModal" tabindex="-1" aria-labelledby="chantierModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="ajouterChantier.php" id="chantierForm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="chantierModalLabel">Créer / Modifier un chantier</h5>
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
                        <label for="chefChantier" class="form-label">Chef de chantier</label>
                        <select class="form-select" id="chefChantier" name="responsable_id" required>
                            <option value="">-- Sélectionner --</option>
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
                    <button type="submit" class="btn btn-success">Sauvegarder</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal confirmation suppression -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" id="deleteForm">
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

<?php require_once "templates/footer.php"; ?>