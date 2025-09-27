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
$agenceFilter = $_GET['agence_id'] ?? null; // peut être "none" ou un ID
$whereAgence = '';
$paramsAgence = [];

if ($agenceFilter === 'none') {
  $whereAgence = " AND COALESCE(c.agence_id, d.agence_id) IS NULL ";
} elseif (ctype_digit((string)$agenceFilter) && (int)$agenceFilter > 0) {
  $whereAgence = " AND COALESCE(c.agence_id, d.agence_id) = :agf ";
  $paramsAgence[':agf'] = (int)$agenceFilter;
}



$sql = "
SELECT
  c.id,
  c.nom,
  c.adresse,
  c.depot_id,
  d.nom AS depot_nom,
  c.description,
  c.date_debut,
  c.date_fin,
  c.trajet_distance_m,
  c.trajet_duree_s,
  c.trajet_last_calc,
  c.etat,


  /* Agence effective : chantier.agence_id sinon depot.agence_id */
  COALESCE(c.agence_id, d.agence_id) AS agence_id,
  a.nom AS agence_nom,

  ur.id AS resp_id,
  CONCAT(COALESCE(ur.prenom, ''), ' ', COALESCE(ur.nom, '')) AS resp_nom,

  GROUP_CONCAT(DISTINCT
    CASE WHEN u_all.fonction IN ('employe','interim','autre','depot')
         THEN CONCAT(u_all.prenom, ' ', u_all.nom)
         ELSE NULL END
    ORDER BY u_all.nom, u_all.prenom SEPARATOR ', '
  ) AS equipe_du_jour,

  GROUP_CONCAT(DISTINCT
    CASE WHEN u_chef.id IS NOT NULL AND u_chef.id <> c.responsable_id
         THEN CONCAT(u_chef.prenom, ' ', u_chef.nom)
         ELSE NULL END
    ORDER BY u_chef.nom, u_chef.prenom SEPARATOR ', '
  ) AS autres_chefs,

  GROUP_CONCAT(DISTINCT u_chef.id) AS chef_ids_all,

  COUNT(DISTINCT CASE
      WHEN u_all.id IS NOT NULL AND u_all.fonction IN ('employe','interim','autre','depot')
      THEN u_all.id END
  ) AS nb_ouvriers_today,

  (CASE WHEN ur.id IS NULL THEN 0 ELSE 1 END)
  + COUNT(DISTINCT CASE
      WHEN u_chef.id IS NOT NULL AND u_chef.id <> c.responsable_id
      THEN u_chef.id END
    ) AS nb_chefs_total,

  (
    COUNT(DISTINCT CASE
      WHEN u_all.id IS NOT NULL AND u_all.fonction IN ('employe','interim','autre','depot')
      THEN u_all.id END
    )
    + (CASE WHEN ur.id IS NULL THEN 0 ELSE 1 END)
    + COUNT(DISTINCT CASE
        WHEN u_chef.id IS NOT NULL AND u_chef.id <> c.responsable_id
        THEN u_chef.id END
      )
  ) AS total_personnes

FROM chantiers c

LEFT JOIN depots d
       ON d.id = c.depot_id
      AND d.entreprise_id = :eid_dep

/* JOIN agences sur l’agence effective (chantier OU dépôt) */
LEFT JOIN agences a
       ON a.id = COALESCE(c.agence_id, d.agence_id)
      AND a.entreprise_id = :eid_ag

LEFT JOIN utilisateurs ur
       ON ur.id = c.responsable_id
      AND ur.fonction = 'chef'
      AND ur.entreprise_id = :eid2

LEFT JOIN planning_affectations pa
       ON pa.chantier_id   = c.id
      AND pa.date_jour     = :d
      AND pa.entreprise_id = :eid3

LEFT JOIN utilisateurs u_all
       ON u_all.id            = pa.utilisateur_id
      AND u_all.entreprise_id = :eid4

LEFT JOIN utilisateur_chantiers uc
       ON uc.chantier_id   = c.id
      AND uc.entreprise_id = :eid5

LEFT JOIN utilisateurs u_chef
       ON u_chef.id            = uc.utilisateur_id
      AND u_chef.fonction      = 'chef'
      AND u_chef.entreprise_id = :eid6

WHERE c.entreprise_id = :eid7
{$whereAgence}

GROUP BY c.id
ORDER BY c.nom
";




$params = [
  ':d'    => $today,
  ':eid_dep' => $entrepriseId,
  ':eid_ag'  => $entrepriseId,
  ':eid2'    => $entrepriseId,
  ':eid3' => $entrepriseId,
  ':eid4' => $entrepriseId,
  ':eid5' => $entrepriseId,
  ':eid6' => $entrepriseId,
  ':eid7' => $entrepriseId,
];
if (isset($paramsAgence[':agf'])) {
  $params[':agf'] = $paramsAgence[':agf'];
}

$st = $pdo->prepare($sql);

$st->bindValue(':d',        $today);                    // date jour
$st->bindValue(':eid_dep',  $entrepriseId, PDO::PARAM_INT); // depots d
$st->bindValue(':eid_ag',   $entrepriseId, PDO::PARAM_INT); // agences a
$st->bindValue(':eid2',     $entrepriseId, PDO::PARAM_INT); // utilisateurs ur
$st->bindValue(':eid3',     $entrepriseId, PDO::PARAM_INT); // planning_affectations pa
$st->bindValue(':eid4',     $entrepriseId, PDO::PARAM_INT); // utilisateurs u_all
$st->bindValue(':eid5',     $entrepriseId, PDO::PARAM_INT); // utilisateur_chantiers uc
$st->bindValue(':eid6',     $entrepriseId, PDO::PARAM_INT); // utilisateurs u_chef
$st->bindValue(':eid7',     $entrepriseId, PDO::PARAM_INT); // WHERE c.entreprise_id

// Liaison conditionnelle si le WHERE inséré par {$whereAgence} contient :agf
if (strpos($whereAgence, ':agf') !== false) {
  $st->bindValue(':agf', (int)$agenceFilter, PDO::PARAM_INT);
}

$st->execute();


$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$stDep = $pdo->prepare("SELECT id, nom, adresse FROM depots WHERE entreprise_id = :eid ORDER BY nom");
$stDep->execute([':eid' => $entrepriseId]);
$depots = $stDep->fetchAll(PDO::FETCH_ASSOC);

/* ====== Agences (pour filtres) ====== */
$stAgences = $pdo->prepare("
  SELECT
    a.id,
    a.nom,
    SUM(
      CASE
        WHEN NULLIF(COALESCE(c.agence_id, d.agence_id), 0) = a.id THEN 1
        ELSE 0
      END
    ) AS nb
  FROM agences a
  LEFT JOIN chantiers c
         ON c.entreprise_id = :eid_c1
  LEFT JOIN depots d
         ON d.id = c.depot_id
        AND d.entreprise_id = :eid_c2
  WHERE a.entreprise_id = :eid_a
  GROUP BY a.id, a.nom
  ORDER BY a.nom
");
$stAgences->execute([
  ':eid_c1' => $entrepriseId,
  ':eid_c2' => $entrepriseId,
  ':eid_a'  => $entrepriseId,
]);
$agences = $stAgences->fetchAll(PDO::FETCH_ASSOC);



$stSansAgence = $pdo->prepare("
  SELECT
    SUM(
      CASE
        WHEN NULLIF(COALESCE(c.agence_id, d.agence_id), 0) IS NULL THEN 1
        ELSE 0
      END
    ) AS nb_sans
  FROM chantiers c
  LEFT JOIN depots d
         ON d.id = c.depot_id
        AND d.entreprise_id = :eid_d
  WHERE c.entreprise_id = :eid_c
");
$stSansAgence->execute([
  ':eid_d' => $entrepriseId,   // pour depots
  ':eid_c' => $entrepriseId,   // pour chantiers
]);
$sansAgenceCount = (int)$stSansAgence->fetchColumn();








// -------- Compteurs En cours / Fini *alignés* sur la logique d'affichage --------
$agf = $_GET['agence_id'] ?? null;

$whereAgenceCount = '';
$paramsCount = [
  ':eid_c' => $entrepriseId, // pour WHERE c.entreprise_id
  ':eid_d' => $entrepriseId, // pour JOIN d.entreprise_id
];

if ($agf === 'none') {
  $whereAgenceCount = " AND COALESCE(c.agence_id, d.agence_id) IS NULL ";
} elseif (ctype_digit((string)$agf) && (int)$agf > 0) {
  $whereAgenceCount = " AND COALESCE(c.agence_id, d.agence_id) = :agf ";
  $paramsCount[':agf'] = (int)$agf;
}

$sqlCount = "
  SELECT
    SUM(
      CASE
        WHEN (c.etat = 'fini') OR (c.date_fin IS NOT NULL AND c.date_fin < CURDATE())
        THEN 1 ELSE 0
      END
    ) AS nb_fini,
    SUM(
      CASE
        WHEN NOT ( (c.etat = 'fini') OR (c.date_fin IS NOT NULL AND c.date_fin < CURDATE()) )
        THEN 1 ELSE 0
      END
    ) AS nb_en_cours
  FROM chantiers c
  LEFT JOIN depots d
         ON d.id = c.depot_id
        AND d.entreprise_id = :eid_d   -- nom distinct
  WHERE c.entreprise_id = :eid_c       -- nom distinct
  $whereAgenceCount
";

$stCnt = $pdo->prepare($sqlCount);
$stCnt->execute($paramsCount);

list($nbFini, $nbEnCours) = array_map('intval', $stCnt->fetch(PDO::FETCH_NUM));




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

  <?php
  $agenceIdFromQS = $_GET['agence_id'] ?? '0';
  $showEtat = ($agenceIdFromQS !== '0'); // visible seulement si une agence est sélectionnée (y compris "none")
  ?>

  <!-- Toolbar CENTRÉE sous le bouton -->
  <div class="d-flex justify-content-center">
    <div class="btn-toolbar flex-column align-items-center gap-2" id="filtersToolbar">

      <!-- 1) Filtres AGENCE (rangée du haut) -->
      <div id="agenceFilters" class="btn-group" role="group" aria-label="Filtre agence">
        <button type="button" class="btn btn-outline-primary" data-agence="0">Tous</button>
        <button type="button" class="btn btn-outline-primary" data-agence="none">
          Sans agence<?= $sansAgenceCount ? " ($sansAgenceCount)" : "" ?>
        </button>
        <?php foreach ($agences as $a): ?>
          <button type="button" class="btn btn-outline-primary" data-agence="<?= (int)$a['id'] ?>">
            <?= htmlspecialchars($a['nom']) ?><?= $a['nb'] ? " (" . (int)$a['nb'] . ")" : "" ?>
          </button>
        <?php endforeach; ?>
      </div>

      <!-- 2) Sous-catégories ÉTAT (rangée du bas, sans "Tous") -->
      <div id="etatFilters" class="btn-group d-block mb-3 <?= $showEtat ? '' : 'd-none' ?>" role="group">
        <button type="button" class="btn btn-outline-secondary" data-etat="en_cours">
          En cours (<span class="etat-count" data-for="en_cours"><?= (int)($nbEnCours ?? 0) ?></span>)
        </button>
        <button type="button" class="btn btn-outline-secondary" data-etat="fini">
          Fini (<span class="etat-count" data-for="fini"><?= (int)($nbFini ?? 0) ?></span>)
        </button>
      </div>


    </div>
  </div>

  <!-- 3) Champ de RECHERCHE (plein largeur, sous la toolbar) -->
  <input type="text"
    id="chantierSearchInput"
    class="form-control my-3"
    placeholder="Rechercher un chantier..."
    autocomplete="off" />

  <table class="table table-striped table-hover table-bordered text-center">
    <thead class="table-dark">
      <tr>
        <th>Nom</th>
        <th>Adresse</th>
        <th>Dépôt</th>
        <th>Chef</th>
        <th>Équipe (aujourd’hui)</th>
        <th>Date début</th>
        <th>Date fin</th>
        <th>Kilomètres</th>
        <th>Durée</th>

        <th>Actions</th>
      </tr>
    </thead>
    <tbody id="chantiersTableBody">
      <?php foreach ($rows as $c): ?>
        <tr class="align-middle"
          data-row-id="<?= (int)($c['id'] ?? 0) ?>"
          data-agence-id="<?= (int)($c['agence_id'] ?? 0) ?>"
          data-etat="<?= htmlspecialchars($c['etat'] ?? 'en_cours') ?>">
          <td>
            <a href="chantier_menu.php?id=<?= (int)$c['id'] ?>">
              <?= htmlspecialchars($c['nom']) ?> (<?= (int)($c['total_personnes'] ?? 0) ?>)
            </a>
          </td>
          <td><?= htmlspecialchars($c['adresse'] ?? '—') ?></td>
          <td><?= htmlspecialchars($c['depot_nom'] ?? '—') ?></td>

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
          <?php
          $km  = isset($c['trajet_distance_m']) ? round(((int)$c['trajet_distance_m']) / 1000, 1) : null;
          $min = isset($c['trajet_duree_s']) ? (int)round(((int)$c['trajet_duree_s']) / 60) : null;
          ?>
          <td class="trajet-km">
            <?= $km !== null ? htmlspecialchars(number_format($km, 1, ',', '')) : '—' ?>
          </td>
          <td class="trajet-min">
            <?= $min !== null ? htmlspecialchars($min) . ' min' : '—' ?>
          </td>


          <td>
            <button class="btn btn-sm btn-warning edit-btn"
              data-bs-toggle="modal" data-bs-target="#chantierEditModal"
              data-id="<?= (int)$c['id'] ?>"
              data-nom="<?= htmlspecialchars($c['nom']) ?>"
              data-adresse="<?= htmlspecialchars($c['adresse'] ?? '', ENT_QUOTES) ?>"
              data-depot-id="<?= (int)($c['depot_id'] ?? 0) ?>"
              data-description="<?= htmlspecialchars($c['description'] ?? '') ?>"
              data-debut="<?= htmlspecialchars($c['date_debut'] ?? '') ?>"
              data-fin="<?= htmlspecialchars($c['date_fin'] ?? '') ?>"
              data-chef-ids="<?= htmlspecialchars($c['chef_ids_all'] ?? '') ?>"
              data-agence-id="<?= (int)($c['agence_id'] ?? 0) ?>"
              title="Modifier">
              <i class="bi bi-pencil-fill"></i>
            </button>

            <button class="btn btn-sm btn-danger delete-btn"
              data-bs-toggle="modal" data-bs-target="#deleteModal"
              data-id="<?= (int)$c['id'] ?>" title="Supprimer">
              <i class="bi bi-trash-fill"></i>
            </button>
            <button class="btn btn-sm btn-outline-primary calc-trajet-btn"
              data-id="<?= (int)$c['id'] ?>"
              data-depot-id="<?= (int)($c['depot_id'] ?? 0) ?>"
              title="Recalculer distance et durée">
              <i class="bi bi-geo-alt"></i>
            </button>
            <?php
  $isFini = ($c['etat'] ?? 'en_cours') === 'fini';
  $toggleTitle = $isFini ? 'Repasser en cours' : 'Marquer comme fini';
  $toggleIcon  = $isFini ? 'bi-arrow-counterclockwise' : 'bi-check2-circle';
  $toggleClass = $isFini ? 'btn-outline-warning' : 'btn-outline-success';
?>
<button
  class="btn btn-sm <?= $toggleClass ?> toggle-etat-btn"
  data-id="<?= (int)$c['id'] ?>"
  data-etat="<?= htmlspecialchars($c['etat'] ?? 'en_cours') ?>"
  title="<?= $toggleTitle ?>"
>
  <i class="bi <?= $toggleIcon ?>"></i>
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
            <label for="chantierAdresse" class="form-label">Adresse du chantier</label>
            <input type="text" class="form-control" id="chantierAdresse" name="adresse"
              placeholder="rue Exemple, 65150 Saint-Paul" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Dépôt (origine du trajet)</label>
            <select class="form-select" id="chantierDepot" name="depot_id" required>
              <option value="">— Sélectionner —</option>
              <?php foreach ($depots as $d): ?>
                <option value="<?= (int)$d['id'] ?>">
                  <?= htmlspecialchars($d['nom'] . ' — ' . $d['adresse']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Agence</label>
            <select class="form-select" id="chantierAgence" name="agence_id">
              <option value="0">— Sans agence —</option>
              <?php foreach ($agences as $a): ?>
                <option value="<?= (int)$a['id'] ?>">
                  <?= htmlspecialchars($a['nom']) ?>
                </option>
              <?php endforeach; ?>
            </select>
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
            <label for="chantierAdresseEdit" class="form-label">Adresse du chantier</label>
            <input type="text" class="form-control" id="chantierAdresseEdit" name="adresse"
              placeholder="rue Exemple, 65150 Saint-Paul" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Dépôt (origine du trajet)</label>
            <select class="form-select" id="chantierDepotEdit" name="depot_id" required>
              <option value="">— Sélectionner —</option>
              <?php foreach ($depots as $d): ?>
                <option value="<?= (int)$d['id'] ?>">
                  <?= htmlspecialchars($d['nom'] . ' — ' . $d['adresse']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Agence</label>
            <select class="form-select" id="chantierAgenceEdit" name="agence_id">
              <option value="0">— Sans agence —</option>
              <?php foreach ($agences as $a): ?>
                <option value="<?= (int)$a['id'] ?>">
                  <?= htmlspecialchars($a['nom']) ?>
                </option>
              <?php endforeach; ?>
            </select>
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

<script src="./js/chantiers_admin.js" defer></script>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const highlightedRow = document.querySelector('tr.table-success');
    if (highlightedRow) setTimeout(() => highlightedRow.classList.remove('table-success'), 3000);
  });
</script>
<script>
  window.CSRF_TOKEN = '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>';
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>