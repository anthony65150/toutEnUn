<?php
require_once __DIR__ . '/../config/init.php';

// === Sécurité : vérifier admin AVANT tout output ===
if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur') {
  header("Location: /connexion.php");
  exit;
}

$entrepriseId = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);
if (!$entrepriseId) {
  // Pas d'entreprise sélectionnée : on bloque proprement
  http_response_code(403);
  exit('Entreprise non définie dans la session.');
}

/* ====== CSRF ====== */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

/* ====== Liste des chefs (même entreprise) ====== */
$stChefs = $pdo->prepare("
  SELECT id, prenom, nom
  FROM utilisateurs
  WHERE fonction = 'chef' AND entreprise_id = :eid
  ORDER BY prenom, nom
");
$stChefs->execute([':eid' => $entrepriseId]);
$chefsOptions = $stChefs->fetchAll(PDO::FETCH_ASSOC);

/* ====== Données chantiers ======
   Chef principal = chantiers.responsable_id
   Autres chefs   = utilisateur_chantiers (fonction = 'chef', hors responsable)
   Équipe         = affectations du jour (hors chefs)
*/
$today = (new DateTime('today'))->format('Y-m-d');
if (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) {
  $today = $_GET['date'];
}

$sql = "
SELECT
  c.id,
  c.nom,
  c.description,
  c.date_debut,
  c.date_fin,

  ur.id AS resp_id,
  CONCAT(COALESCE(ur.prenom,''), ' ', COALESCE(ur.nom,'')) AS resp_nom,

  /* Équipe du jour (hors chefs) : employés + intérims + autres + dépôt */
  GROUP_CONCAT(DISTINCT
    CASE WHEN u_all.fonction IN ('employe','interim','autre','depot')
         THEN CONCAT(u_all.prenom, ' ', u_all.nom)
         ELSE NULL END
    ORDER BY u_all.nom, u_all.prenom SEPARATOR ', '
  ) AS equipe_du_jour,

  /* Autres chefs (que le responsable) */
  GROUP_CONCAT(DISTINCT
    CASE WHEN u_chef.id IS NOT NULL AND u_chef.id <> c.responsable_id
         THEN CONCAT(u_chef.prenom, ' ', u_chef.nom)
         ELSE NULL END
    ORDER BY u_chef.nom, u_chef.prenom SEPARATOR ', '
  ) AS autres_chefs,

  GROUP_CONCAT(DISTINCT u_chef.id) AS chef_ids_all,

  /* ===== Compteurs ===== */
  /* Employés + intérims + autres + dépôt affectés aujourd'hui (distinct) */
  COUNT(DISTINCT CASE
      WHEN u_all.id IS NOT NULL AND u_all.fonction IN ('employe','interim','autre','depot')
      THEN u_all.id END
  ) AS nb_ouvriers_today,

  /* Tous les chefs : responsable (si présent) + autres chefs distincts */
  (CASE WHEN ur.id IS NULL THEN 0 ELSE 1 END)
  + COUNT(DISTINCT CASE
      WHEN u_chef.id IS NOT NULL AND u_chef.id <> c.responsable_id
      THEN u_chef.id END
    ) AS nb_chefs_total,

  /* total pour l’affichage à côté du nom */
  (
    COUNT(DISTINCT CASE
      WHEN u_all.id IS NOT NULL AND u_all.fonction IN ('employe','interim','autre','depot')
      THEN u_all.id END
    )
    +
    (CASE WHEN ur.id IS NULL THEN 0 ELSE 1 END)
    + COUNT(DISTINCT CASE
        WHEN u_chef.id IS NOT NULL AND u_chef.id <> c.responsable_id
        THEN u_chef.id END
      )
  ) AS total_personnes

FROM chantiers c
LEFT JOIN utilisateurs ur
       ON ur.id = c.responsable_id
      AND ur.fonction = 'chef'
      AND ur.entreprise_id = :eid1
LEFT JOIN planning_affectations pa
       ON pa.chantier_id   = c.id
      AND pa.date_jour     = :d
      AND pa.entreprise_id = :eid2
LEFT JOIN utilisateurs u_all
       ON u_all.id            = pa.utilisateur_id
      AND u_all.entreprise_id = :eid3
LEFT JOIN utilisateur_chantiers uc
       ON uc.chantier_id   = c.id
      AND uc.entreprise_id = :eid4
LEFT JOIN utilisateurs u_chef
       ON u_chef.id            = uc.utilisateur_id
      AND u_chef.fonction      = 'chef'
      AND u_chef.entreprise_id = :eid5
WHERE c.entreprise_id = :eid6
GROUP BY c.id
ORDER BY c.nom
";



$st = $pdo->prepare($sql);
$st->execute([
  ':d'    => $today,
  ':eid1' => $entrepriseId,
  ':eid2' => $entrepriseId,
  ':eid3' => $entrepriseId,
  ':eid4' => $entrepriseId,
  ':eid5' => $entrepriseId,
  ':eid6' => $entrepriseId,
]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);




require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/navigation/navigation.php';
?>
<div class="container mt-4">
  <h1 class="mb-4 text-center">Gestion des chantiers</h1>

  <!-- Bouton création -->
  <div class="d-flex justify-content-center mb-3">
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#chantierModal">
      + Créer un chantier
    </button>
  </div>

  <input type="text" id="chantierSearchInput" class="form-control mb-4" placeholder="Rechercher un chantier..." autocomplete="off" />

  <table class="table table-striped table-hover table-bordered text-center">
  <thead class="table-dark">
    <tr>
      <th>Nom</th>
      <th>Chef</th>
      <th>Équipe (aujourd’hui)</th>
      <th>Date début</th>
      <th>Date fin</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody id="chantiersTableBody">
    <?php foreach ($rows as $c): ?>
      <tr class="align-middle" data-row-id="<?= (int)$c['id'] ?>">
        <td>
          <a href="chantier_menu.php?id=<?= (int)$c['id'] ?>">
            <?= htmlspecialchars($c['nom']) ?> (<?= (int)($c['total_personnes'] ?? 0) ?>)
          </a>
        </td>

        <td class="text-center">
          <?php if (!empty($c['resp_nom'])): ?>
            <?= htmlspecialchars($c['resp_nom']) ?>
            <?php if (!empty($c['autres_chefs'])): ?>
              <div class="small text-muted">+ <?= htmlspecialchars($c['autres_chefs']) ?></div>
            <?php endif; ?>
          <?php else: ?>
            <span class="text-muted">—</span>
          <?php endif; ?>
        </td>

        <td class="text-start">
          <?= !empty($c['equipe_du_jour']) ? htmlspecialchars($c['equipe_du_jour']) : '—' ?>
        </td>

        <td><?= htmlspecialchars($c['date_debut'] ?? '') ?></td>
        <td><?= htmlspecialchars($c['date_fin'] ?? '') ?></td>

        <td>
          <button class="btn btn-sm btn-warning edit-btn"
            data-bs-toggle="modal" data-bs-target="#chantierEditModal"
            data-id="<?= (int)$c['id'] ?>"
            data-nom="<?= htmlspecialchars($c['nom']) ?>"
            data-description="<?= htmlspecialchars($c['description'] ?? '') ?>"
            data-debut="<?= htmlspecialchars($c['date_debut'] ?? '') ?>"
            data-fin="<?= htmlspecialchars($c['date_fin'] ?? '') ?>"
            data-chef-ids="<?= htmlspecialchars($c['chef_ids_all'] ?? '') ?>"
            title="Modifier">
            <i class="bi bi-pencil-fill"></i>
          </button>

          <button class="btn btn-sm btn-danger delete-btn"
            data-bs-toggle="modal" data-bs-target="#deleteModal"
            data-id="<?= (int)$c['id'] ?>" title="Supprimer">
            <i class="bi bi-trash-fill"></i>
          </button>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

</div>

<!-- Modal création -->
<div class="modal fade" id="chantierModal" tabindex="-1" aria-labelledby="chantierModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" action="ajouterChantier.php" id="chantierForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="entreprise_id" value="<?= (int)$entrepriseId ?>">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="chantierModalLabel">Créer un chantier</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="chantier_id" id="chantierId" value="">
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
            <select class="form-select" id="chefChantier" name="chefs[]" multiple size="6">
              <?php foreach ($chefsOptions as $opt): ?>
                <option value="<?= (int)$opt['id'] ?>"><?= htmlspecialchars($opt['prenom'] . ' ' . $opt['nom']) ?></option>
              <?php endforeach; ?>
            </select>
            <small class="text-muted">Maintiens Ctrl/Cmd pour sélection multiple. Le premier sera le responsable.</small>
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
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="entreprise_id" value="<?= (int)$entrepriseId ?>">
      <input type="hidden" name="chantier_id" id="chantierIdEdit" value="">
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
            <select class="form-select" id="chefChantierEdit" name="chefs[]" multiple size="6">
              <?php foreach ($chefsOptions as $opt): ?>
                <option value="<?= (int)$opt['id'] ?>"><?= htmlspecialchars($opt['prenom'] . ' ' . $opt['nom']) ?></option>
              <?php endforeach; ?>
            </select>
            <small class="text-muted">Maintiens Ctrl/Cmd pour sélection multiple. Le premier sera le responsable.</small>
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

<!-- Modal suppression -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" action="supprimerChantier.php" id="deleteForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="delete_id" id="deleteId">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Confirmer la suppression</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">Es-tu sûr de vouloir supprimer ce chantier ? Cette action est irréversible.</div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-danger">Supprimer</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1055">
  <div id="chantierToast" class="toast align-items-center text-white bg-success border-0" role="alert">
    <div class="d-flex">
      <div class="toast-body" id="chantierToastMsg">Chantier enregistré avec succès.</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<?php if (isset($_GET['success'])): ?>
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const type = "<?= htmlspecialchars($_GET['success']) ?>";
      let message = "Chantier enregistré avec succès.";
      if (type === "create") message = "Chantier créé avec succès.";
      else if (type === "update") message = "Chantier modifié avec succès.";
      else if (type === "delete") message = "Chantier supprimé avec succès.";
      showChantierToast(message);
    });
  </script>
<?php endif; ?>

<script src="./js/chantiers_admin.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const highlightedRow = document.querySelector('tr.table-success');
    if (highlightedRow) setTimeout(() => highlightedRow.classList.remove('table-success'), 3000);
  });
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>