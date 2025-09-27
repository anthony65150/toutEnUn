<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/navigation/navigation.php';

/* =========================
   Sécurité + Contexte
   ========================= */
if (!isset($_SESSION['utilisateurs']) || (($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur')) {
    header("Location: ../connexion.php");
    exit;
}
$entrepriseId = (int)($_SESSION['entreprise_id'] ?? 0);
if ($entrepriseId <= 0) {
    // Pas d'entreprise sélectionnée → on bloque
    http_response_code(403);
    exit('Entreprise non sélectionnée');
}

/* =========================
   Données nécessaires
   ========================= */
/* Utilisateurs de la même entreprise (pour le select "Responsable") */
$stUsers = $pdo->prepare("
    SELECT id, prenom, nom, fonction
    FROM utilisateurs
    WHERE entreprise_id = :eid
    ORDER BY nom, prenom
");
$stUsers->execute([':eid' => $entrepriseId]);
$utilisateurs = $stUsers->fetchAll(PDO::FETCH_ASSOC);

/* Dépôts de l'entreprise avec le responsable en JOIN (évite N+1 requêtes) */
$stDepots = $pdo->prepare("
    SELECT d.id,
           d.nom,
           d.adresse,        
           d.created_at,
           d.responsable_id,
           u.prenom  AS resp_prenom,
           u.nom     AS resp_nom
    FROM depots d
    LEFT JOIN utilisateurs u ON u.id = d.responsable_id
    WHERE d.entreprise_id = :eid
    ORDER BY d.nom ASC
");

$stDepots->execute([':eid' => $entrepriseId]);
$depots = $stDepots->fetchAll(PDO::FETCH_ASSOC);
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
    <table class="table table-striped table-hover table-bordered text-center">
        <thead class="table-dark">
            <tr>
                <th>Nom</th>
                <th>Adresse</th> <!-- NEW -->
                <th>Responsable</th>
                <th>Date de création</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="depotsTbody">
            <?php foreach ($depots as $depot): ?>
                <?php
                $respText = (!empty($depot['responsable_id']) && $depot['resp_prenom'] !== null)
                    ? htmlspecialchars($depot['resp_prenom'] . ' ' . $depot['resp_nom'])
                    : '—';
                $createdAt = !empty($depot['created_at'])
                    ? htmlspecialchars(date('d/m/Y', strtotime($depot['created_at'])))
                    : '—';
                $highlight = (isset($_GET['highlight']) && (int)$_GET['highlight'] === (int)$depot['id']) ? 'table-success' : '';
                ?>
                <tr class="align-middle <?= $highlight ?>">
                    <td>
                        <a class="link-primary fw-semibold text-decoration-none"
                            href="./depot_contenu.php?depot_id=<?= (int)$depot['id'] ?>">
                            <?= htmlspecialchars($depot['nom']) ?>
                        </a>
                    </td>
                    <td><?= htmlspecialchars($depot['adresse'] ?? '—') ?></td> <!-- NEW -->
                    <td><?= $respText ?></td>
                    <td><?= $createdAt ?></td>
                    <td>
                        <button class="btn btn-sm btn-warning edit-depot-btn"
                            data-bs-toggle="modal"
                            data-bs-target="#modalDepotEdit"
                            data-id="<?= (int)$depot['id'] ?>"
                            data-nom="<?= htmlspecialchars($depot['nom'], ENT_QUOTES) ?>"
                            data-adresse="<?= htmlspecialchars($depot['adresse'] ?? '', ENT_QUOTES) ?>"
                            data-resp="<?= (int)($depot['responsable_id'] ?? 0) ?>"
                            title="Modifier">
                            <i class="bi bi-pencil-fill"></i>
                        </button>
                        <button class="btn btn-sm btn-danger delete-depot-btn"
                            data-bs-toggle="modal"
                            data-bs-target="#modalDepotDelete"
                            data-id="<?= (int)$depot['id'] ?>"
                            title="Supprimer">
                            <i class="bi bi-trash-fill"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>


            <!-- ligne "aucun résultat" (affichée par JS si besoin) -->
            <tr id="noResultsRow" class="d-none">
                <td colspan="5" class="text-muted text-center py-4">Aucun dépôt trouvé</td>
            </tr>
        </tbody>
    </table>
</div>

<!-- Modal création -->
<div class="modal fade" id="modalDepotCreate" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form id="formDepotCreate">
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
                        <label class="form-label">Adresse du dépôt</label>
                        <input id="createAdresse" name="adresse" class="form-control" required autocomplete="off">
                        <input type="hidden" id="createLat" name="adresse_lat">
                        <input type="hidden" id="createLng" name="adresse_lng">

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
                    <input type="hidden" id="editDepotId" name="id">

                    <div class="mb-3">
                        <label class="form-label">Nom du dépôt</label>
                        <input type="text" class="form-control" id="editNom" name="nom" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Adresse du dépôt</label>
                        <input id="editAdresse" name="adresse" class="form-control" required autocomplete="off">
                        <input type="hidden" id="editLat" name="adresse_lat">
                        <input type="hidden" id="editLng" name="adresse_lng">

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
                    <input type="hidden" id="deleteDepotId" name="id">
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
            const msgType = "<?= htmlspecialchars($_GET['success'] ?? '', ENT_QUOTES) ?>";
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

<!-- JS admin dépôts : chemin relatif depuis /depots -->
<script src="./js/depotsGestion_admin.js?v=7"></script>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const highlightedRow = document.querySelector('tr.table-success');
        if (highlightedRow) {
            setTimeout(() => highlightedRow.classList.remove('table-success'), 3000);
        }
    });
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>