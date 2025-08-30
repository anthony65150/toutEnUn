<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/navigation/navigation.php';

if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? null) !== 'administrateur') {
    header("Location: /connexion.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$utilisateurs = $pdo->query("
  SELECT id, nom, prenom, email, fonction, photo, agence_id
  FROM utilisateurs
  ORDER BY nom, prenom
")->fetchAll(PDO::FETCH_ASSOC);


// Valeurs stockées en base -> Libellés affichés
$ROLE_OPTIONS = [
    'administrateur' => 'Administrateur',
    'depot'          => 'Dépôt',
    'chef'           => 'Chef',
    'employe'        => 'Employé',
    'autre'          => 'Autre',
];

function badgeRole($role)
{
    // accepte "employe" ou "employé"
    $r = mb_strtolower($role);
    if ($r === 'employé') $r = 'employe';
    switch ($r) {
        case 'administrateur':
            return '<span class="badge bg-danger">Administrateur</span>';
        case 'depot':
            return '<span class="badge bg-info text-dark">Dépôt</span>';
        case 'chef':
            return '<span class="badge bg-success">Chef</span>';
        case 'employe':
            return '<span class="badge bg-warning text-dark">Employé</span>';
        case 'autre':
        default:
            return '<span class="badge bg-secondary">Autre</span>';
    }
}
?>
<div class="container mt-4">
    <h1 class="mb-4 text-center">Employés</h1>

    <div class="d-flex justify-content-center mb-3">
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#employeModal">
            + Ajouter un employé
        </button>
    </div>
    <div id="agenceFilters" class="mb-3 d-flex flex-wrap gap-2 justify-content-center"></div>


    <input
        type="text"
        id="employeSearchInput"
        class="form-control mb-4"
        placeholder="Rechercher un employé (nom, email, rôle)..."
        autocomplete="off" />

    <div class="table-responsive">
        <table class="table table-bordered table-hover table-striped align-middle">
            <thead class="table-dark">
                <tr>
                    <th>#</th>
                    <th>Nom prénom</th>
                    <th>Email</th>
                    <th>Rôle</th>
                    <th style="width:140px">Actions</th>
                </tr>
            </thead>
            <tbody id="employesTableBody">
                <?php foreach ($utilisateurs as $u): ?>
                    <tr
                        data-id="<?= (int)$u['id'] ?>"
                        data-nom="<?= htmlspecialchars($u['nom']) ?>"
                        data-prenom="<?= htmlspecialchars($u['prenom']) ?>"
                        data-email="<?= htmlspecialchars($u['email'] ?? '') ?>"
                        data-fonction="<?= htmlspecialchars($u['fonction']) ?>"
                        data-agence-id="<?= (int)($u['agence_id'] ?? 0) ?>"> <!-- <= ICI, dans la balise -->
                        <td><?= (int)$u['id'] ?></td>
                        <td><strong><?= htmlspecialchars($u['nom'] . ' ' . $u['prenom']) ?></strong></td>
                        <td><?= htmlspecialchars($u['email'] ?? '') ?></td>
                        <td><?= badgeRole($u['fonction']) ?></td>
                        <td class="text-center">
                            <button class="btn btn-warning btn-sm edit-btn" title="Modifier"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-danger btn-sm delete-btn" title="Supprimer"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>

        </table>
    </div>
</div>

<!-- Modal Création / Édition -->
<div class="modal fade" id="employeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="employeForm">
            <div class="modal-header">
                <h5 class="modal-title" id="employeModalTitle">Ajouter un employé</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="id" id="emp_id">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label">Prénom</label>
                        <input type="text" name="prenom" id="emp_prenom" class="form-control" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Nom</label>
                        <input type="text" name="nom" id="emp_nom" class="form-control" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="emp_email" class="form-control" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Rôle</label>
                        <select name="fonction" id="emp_fonction" class="form-select" required>
                            <?php foreach ($ROLE_OPTIONS as $value => $label): ?>
                                <option value="<?= $value ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="emp_agence" class="form-label">Agence</label>
                        <select name="agence_id" id="emp_agence" class="form-select">
                            <option value="">-- Sélectionner une agence --</option>
                        </select>

                        <!-- le petit lien sous le select, comme pour Catégorie -->
                        <a href="#" id="openAgenceLink" class="small d-inline-block mt-2">
                            + Ajouter une agence
                        </a>
                    </div>



                    <div class="col-12">
                        <label class="form-label">Mot de passe</label>
                        <input type="password" name="password" id="emp_password" class="form-control" placeholder="Mot de passe">
                        <div class="form-text">Obligatoire à la création. Laissez vide en modification si vous ne souhaitez pas changer le mot de passe.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Annuler</button>
                <button class="btn btn-primary" type="submit">Enregistrer</button>
            </div>
        </form>
    </div>
</div>
<!-- Mini-modale Agence -->
<div class="modal fade" id="agenceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form id="agenceForm" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Ajouter une agence</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <div class="mb-3">
                    <label class="form-label">Nom de l’agence</label>
                    <input type="text" class="form-control" name="nom" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Adresse (optionnel)</label>
                    <input type="text" class="form-control" name="adresse">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" class="btn btn-primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>


<!-- Modal Suppression -->
<div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="deleteForm">
            <div class="modal-header">
                <h5 class="modal-title">Supprimer l’employé</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="id" id="delete_id">
                <p class="mb-0">Confirmer la suppression ? Cette action est irréversible.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Annuler</button>
                <button class="btn btn-danger" type="submit">Supprimer</button>
            </div>
        </form>
    </div>
</div>

<!-- Helper agences réutilisable -->
<script src="/agences/js/agences.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const agenceSelect = document.getElementById('emp_agence');
        const openAgenceModalBtn = document.getElementById('openAgenceModal');
        const agenceModalEl = document.getElementById('agenceModal');
        const agenceModal = agenceModalEl ? new bootstrap.Modal(agenceModalEl) : null;
        const agenceForm = document.getElementById('agenceForm');

        // 1) Charger les agences quand la grande modale s'ouvre (création OU édition)
        const bigModal = document.getElementById('employeModal');
        if (bigModal && window.Agences) {
            bigModal.addEventListener('shown.bs.modal', () => {
                // si tu pré-remplis l’édition avec un data-* dans ton JS admin, emp_agence.value sera conservée
                Agences.loadIntoSelect(agenceSelect, {
                    includePlaceholder: true,
                    preselect: agenceSelect.value || ''
                });
            });
        }

        // 2) Ouvrir la mini-modale
        openAgenceModalBtn?.addEventListener('click', () => {
            agenceForm?.reset();
            agenceModal?.show();
        });

        // 3) Créer l’agence puis la sélectionner automatiquement
        agenceForm?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(agenceForm);
            fd.append('action', 'create');
            try {
                const res = await fetch('/agences/api.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                const data = await res.json();
                if (!data.ok) {
                    alert(data.msg || 'Erreur');
                    return;
                }
                agenceModal?.hide();
                await Agences.loadIntoSelect(agenceSelect, {
                    includePlaceholder: true,
                    preselect: String(data.id)
                });
            } catch (err) {
                console.error(err);
                alert('Erreur réseau');
            }
        });
    });
</script>


<script src="/agences/js/agences.js"></script>
<script src="js/employesGestion_admin.js"></script>


<?php require_once __DIR__ . '/../templates/footer.php'; ?>