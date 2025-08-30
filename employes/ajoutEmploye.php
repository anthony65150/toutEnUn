<?php
declare(strict_types=1);
ob_start();

/* ========= Boot ========= */
require_once __DIR__ . '/../config/init.php';

/* ========= Accès admin (avant tout output) ========= */
if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur') {
    header('Location: /connexion.php');
    exit;
}
$entrepriseId = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);

/* ========= Includes UI / helpers ========= */
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../fonctions/utilisateurs.php';
require_once __DIR__ . '/../templates/navigation/navigation.php';

/* ========= CSRF ========= */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

/* ========= Normalisation rôle (avec fallback sans mbstring) ========= */
function normalize_role_input(string $r): string {
    if (function_exists('mb_strtolower')) {
        $r = mb_strtolower(trim($r), 'UTF-8');
    } else {
        $r = strtolower(trim($r));
    }
    $r = strtr($r, [
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'à'=>'a','â'=>'a',
        'î'=>'i','ï'=>'i',
        'ô'=>'o','ö'=>'o',
        'ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c'
    ]);
    if (!in_array($r, ['employe','chef','depot','administrateur'], true)) $r = '';
    return $r;
}

/* ========= Alias sécurité si fonction mal nommée ========= */
if (!function_exists('ajoutUtilisateur') && function_exists('ajouUtilisateur')) {
    function ajoutUtilisateur($pdo, $nom, $prenom, $email, $motDePasse, $fonction, $chantier_id = null, $agence_id = null) {
        return ajoutUtilisateur($pdo, $nom, $prenom, $email, $motDePasse, $fonction, $chantier_id, $agence_id);
    }
}

/* ========= Données nécessaires ========= */
$chantiers = $pdo->query("SELECT id, nom FROM chantiers ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

/* ========= Traitement formulaire ========= */
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors['csrf'] = "Session expirée, veuillez réessayer.";
    }

    $nom        = trim((string)($_POST['nom'] ?? ''));
    $prenom     = trim((string)($_POST['prenom'] ?? ''));
    $email      = trim((string)($_POST['email'] ?? ''));
    $motDePasse = (string)($_POST['motDePasse'] ?? '');
    // Accepte "employé" ou "employe"
    $fonctionIn = $_POST['fonction'] ?? '';
    $fonction   = normalize_role_input($fonctionIn === 'employé' ? 'employe' : $fonctionIn);

    // --- NOUVEAU : Agence
    $agence_id = isset($_POST['agence_id']) && $_POST['agence_id'] !== '' ? (int)$_POST['agence_id'] : null;
    if ($agence_id !== null) {
        $chkAg = $pdo->prepare("SELECT 1 FROM agences WHERE id=? AND entreprise_id=? AND actif=1");
        $chkAg->execute([$agence_id, $entrepriseId]);
        if (!$chkAg->fetch()) {
            $errors['agence_id'] = "Agence invalide.";
        }
    }

    // Chantier si chef
    $chantier_id = null;
    if ($fonction === 'chef') {
        $chantier_id = isset($_POST['chantier_id']) && $_POST['chantier_id'] !== '' ? (int)$_POST['chantier_id'] : null;
        if (!$chantier_id) {
            $errors['chantier_id'] = "Merci de choisir un chantier pour un chef.";
        }
    }

    // Validations basiques
    if ($nom === '')                                  $errors['nom'] = "Le nom est obligatoire.";
    if ($prenom === '')                               $errors['prenom'] = "Le prénom est obligatoire.";
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = "Email invalide.";
    if ($motDePasse === '' || strlen($motDePasse) < 6)               $errors['motDePasse'] = "Mot de passe requis (min 6 caractères).";
    if ($fonction === '')                             $errors['fonction'] = "Merci de sélectionner une fonction.";

    // Unicité email
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors['email'] = "Cet email est déjà utilisé.";
        }
    }

    // Validation métier via ta fonction si dispo
    if (empty($errors) && function_exists('verifieUtilisateur')) {
        $verif = verifieUtilisateur([
            'nom'         => $nom,
            'prenom'      => $prenom,
            'email'       => $email,
            'motDePasse'  => $motDePasse,
            'fonction'    => $fonction,
            'chantier_id' => $chantier_id,
            'agence_id'   => $agence_id,
        ]);
        if ($verif !== true && is_array($verif)) {
            $errors = array_merge($errors, $verif);
        }
    }

    // Insertion
    if (empty($errors)) {
        if (!function_exists('ajoutUtilisateur')) {
            // Fallback direct si tu n'as pas de helper
            $hash = password_hash($motDePasse, PASSWORD_DEFAULT);
            if ($fonction === 'chef' && $chantier_id) {
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO utilisateurs (nom, prenom, email, motDePasse, fonction, entreprise_id, agence_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$nom, $prenom, $email, $hash, $fonction, $entrepriseId, $agence_id]);

                    $uid = (int)$pdo->lastInsertId();
                    // lier le chef au chantier (adapte le nom de table si différent)
                    $link = $pdo->prepare("INSERT INTO utilisateur_chantiers (utilisateur_id, chantier_id) VALUES (?, ?)");
                    $link->execute([$uid, $chantier_id]);

                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $errors['global'] = "Erreur à l'insertion: " . $e->getMessage();
                }
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO utilisateurs (nom, prenom, email, motDePasse, fonction, entreprise_id, agence_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$nom, $prenom, $email, $hash, $fonction, $entrepriseId, $agence_id]);
            }
        } else {
            // Utilise ton helper (prévois de l’étendre pour agence_id)
            ajoutUtilisateur($pdo, $nom, $prenom, $email, $motDePasse, $fonction, $chantier_id, $agence_id);
        }

        if (empty($errors)) {
            header("Location: /employes/employes.php");
            exit;
        }
    }
}
?>

<div class="fond-gris">
  <div class="p-5">
    <div class="container mt-1">
      <h1 class="mb-3 text-center">Ajout employés</h1>

      <?php if (!empty($errors['global'])): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($errors['global']) ?></div>
      <?php endif; ?>

      <form action="" method="post" class="mx-auto" style="max-width: 1000px;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

        <div class="mb-3">
          <label class="form-label" for="nom">Nom</label>
          <input type="text" name="nom" id="nom" class="form-control" value="<?= htmlspecialchars($_POST['nom'] ?? '') ?>">
          <?php if (isset($errors['nom'])): ?>
            <div class="alert alert-danger mt-2 p-2"><?= htmlspecialchars($errors['nom']) ?></div>
          <?php endif; ?>
        </div>

        <div class="mb-3">
          <label class="form-label" for="prenom">Prénom</label>
          <input type="text" name="prenom" id="prenom" class="form-control" value="<?= htmlspecialchars($_POST['prenom'] ?? '') ?>">
          <?php if (isset($errors['prenom'])): ?>
            <div class="alert alert-danger mt-2 p-2"><?= htmlspecialchars($errors['prenom']) ?></div>
          <?php endif; ?>
        </div>

        <div class="mb-3">
          <label class="form-label" for="email">Email</label>
          <input type="email" name="email" id="email" class="form-control" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
          <?php if (isset($errors['email'])): ?>
            <div class="alert alert-danger mt-2 p-2"><?= htmlspecialchars($errors['email']) ?></div>
          <?php endif; ?>
        </div>

        <div class="mb-3">
          <label class="form-label" for="motDePasse">Mot de passe</label>
          <input type="password" name="motDePasse" id="motDePasse" class="form-control" autocomplete="new-password">
          <?php if (isset($errors['motDePasse'])): ?>
            <div class="alert alert-danger mt-2 p-2"><?= htmlspecialchars($errors['motDePasse']) ?></div>
          <?php endif; ?>
        </div>

        <div class="mb-3">
          <label for="fonction" class="form-label">Fonction</label>
          <select name="fonction" id="fonction" class="form-select">
            <option value="">-- Choisir une fonction --</option>
            <option value="employe"        <?= (($_POST['fonction'] ?? '') === 'employe' || ($_POST['fonction'] ?? '') === 'employé') ? 'selected' : '' ?>>Employé</option>
            <option value="chef"           <?= (($_POST['fonction'] ?? '') === 'chef') ? 'selected' : '' ?>>Chef</option>
            <option value="depot"          <?= (($_POST['fonction'] ?? '') === 'depot') ? 'selected' : '' ?>>Dépôt</option>
            <option value="administrateur" <?= (($_POST['fonction'] ?? '') === 'administrateur') ? 'selected' : '' ?>>Administrateur</option>
          </select>
          <?php if (isset($errors['fonction'])): ?>
            <div class="alert alert-danger mt-2 p-2"><?= htmlspecialchars($errors['fonction']) ?></div>
          <?php endif; ?>
        </div>

        <!-- NOUVEAU : Agence -->
        <div class="mb-3">
          <label for="agence_id" class="form-label">Agence</label>
          <div class="d-flex gap-2">
            <select name="agence_id" id="agence_id" class="form-select" style="min-width:280px;">
              <option value="">-- Sélectionner une agence --</option>
            </select>
            <button type="button" class="btn btn-outline-secondary" id="openAgenceModal">+ Ajouter une agence</button>
          </div>
          <div class="form-text">Facultatif : rattache l’employé à une agence.</div>
          <?php if (isset($errors['agence_id'])): ?>
            <div class="alert alert-danger mt-2 p-2"><?= htmlspecialchars($errors['agence_id']) ?></div>
          <?php endif; ?>
        </div>

        <!-- Chantier à attribuer (uniquement si chef sélectionné) -->
        <div class="mb-3" id="chantierContainer" style="display:none;">
          <label for="chantier_id" class="form-label">Affecter au chantier</label>
          <select name="chantier_id" id="chantier_id" class="form-select">
            <option value="">-- Choisir un chantier --</option>
            <?php foreach ($chantiers as $chantier): ?>
              <option value="<?= (int)$chantier['id'] ?>" <?= (($_POST['chantier_id'] ?? '') == $chantier['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($chantier['nom']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['chantier_id'])): ?>
            <div class="alert alert-danger mt-2 p-2"><?= htmlspecialchars($errors['chantier_id']) ?></div>
          <?php endif; ?>
        </div>

        <div class="text-center mt-5">
          <button class="btn btn-primary w-50" type="submit" name="ajoutUtilisateur">Ajouter</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- MINI-MODALE : Ajouter une agence -->
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

<script src="/agences/js/agences.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
  const fonctionSelect = document.getElementById('fonction');
  const chantierContainer = document.getElementById('chantierContainer');

  function toggleChantier() {
    chantierContainer.style.display = (fonctionSelect.value === 'chef') ? 'block' : 'none';
  }
  fonctionSelect.addEventListener('change', toggleChantier);
  toggleChantier(); // au chargement

  // === Agences ===
  const agenceSelect = document.getElementById('agence_id');
  const openAgenceModalBtn = document.getElementById('openAgenceModal');
  const agenceModalEl = document.getElementById('agenceModal');
  const agenceModal = agenceModalEl ? new bootstrap.Modal(agenceModalEl) : null;
  const agenceForm = document.getElementById('agenceForm');

  // Charger la liste au chargement
  if (agenceSelect && window.Agences) {
    const pre = "<?= isset($_POST['agence_id']) ? htmlspecialchars((string)$_POST['agence_id']) : '' ?>";
    Agences.loadIntoSelect(agenceSelect, { includePlaceholder:true, preselect: pre });
  }

  // Ouvrir mini-modale
  openAgenceModalBtn?.addEventListener('click', () => {
    agenceForm?.reset();
    agenceModal?.show();
  });

  // Créer agence puis sélectionner
  agenceForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(agenceForm);
    fd.append('action','create');

    try {
      const res = await fetch('/agences/api.php', { method:'POST', body:fd, credentials:'same-origin' });
      const data = await res.json();
      if (!data.ok) { alert(data.msg || 'Erreur'); return; }
      agenceModal?.hide();
      await Agences.loadIntoSelect(agenceSelect, { includePlaceholder:true, preselect: String(data.id) });
    } catch (err) {
      console.error(err); alert('Erreur réseau');
    }
  });
});
</script>

<?php
require_once __DIR__ . '/../templates/footer.php';
ob_end_flush();
