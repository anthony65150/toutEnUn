<?php
// /agences/agences.php
require_once __DIR__ . '/../config/init.php';
requireAuthPage();

if (!isset($_SESSION['utilisateurs']) || empty($_SESSION['entreprise_id'])) {
  header('Location: /connexion.php'); exit;
}

// Option: restreindre aux admins
// if (($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur') { header('Location: /'); exit; }

$csrf = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8');
?>
<?php require_once __DIR__ . '/../templates/header.php'; ?>

<div class="container my-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h3 mb-0">Agences</h1>
    <button class="btn btn-primary" id="btnNewAgence" type="button">+ Nouvelle agence</button>
  </div>

  <div class="mb-3">
    <input id="agenceSearch" type="search" class="form-control" placeholder="Rechercher une agence..." autocomplete="off">
  </div>

  <div class="table-responsive">
    <table class="table table-hover align-middle" id="agencesTable">
      <thead class="table-light">
        <tr>
          <th style="width:80px">#</th>
          <th>Nom</th>
          <th>Adresse</th>
          <th style="width:160px">Actions</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
  </div>
</div>

<!-- Modal create/edit -->
<div class="modal fade" id="agenceEditModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog">
    <form class="modal-content" id="agenceEditForm" novalidate>
      <div class="modal-header">
        <h5 class="modal-title">Agence</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="id" id="agenceId">
        <div class="mb-3">
          <label for="agenceNom" class="form-label">Nom</label>
          <input type="text" name="nom" id="agenceNom" class="form-control" required>
          <div class="invalid-feedback">Le nom est obligatoire.</div>
        </div>
        <div class="mb-3">
          <label for="agenceAdresse" class="form-label">Adresse (optionnel)</label>
          <input type="text" name="adresse" id="agenceAdresse" class="form-control">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
        <button type="submit" class="btn btn-primary">Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>

<!-- Important: même nom de champ CSRF que dans l'API -->
<script defer src="/agences/js/agences.js?v=1"></script>
