<?php
require_once __DIR__ . '/../config/init.php';

if (!isset($_SESSION['utilisateurs'])) {
    header("Location: /connexion.php");
    exit;
}

$user         = $_SESSION['utilisateurs'];
$role         = $user['fonction'] ?? null;
$entrepriseId = (int)($user['entreprise_id'] ?? 0);
if (!$entrepriseId) {
    http_response_code(403);
    exit('Entreprise non définie.');
}

/* ID chantier depuis id|chantier_id */
$chantierId = (int)($_GET['chantier_id'] ?? ($_GET['id'] ?? 0));
if (!$chantierId) {
    require_once __DIR__ . '/../templates/header.php';
    require_once __DIR__ . '/../templates/navigation/navigation.php';
    echo '<div class="container mt-4 alert alert-danger">ID de chantier manquant.</div>';
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

/* Charger chantier et sécuriser entreprise */
$stCh = $pdo->prepare("SELECT id, nom FROM chantiers WHERE id = ? AND entreprise_id = ?");
$stCh->execute([$chantierId, $entrepriseId]);
$chantier = $stCh->fetch(PDO::FETCH_ASSOC);
if (!$chantier) {
    require_once __DIR__ . '/../templates/header.php';
    require_once __DIR__ . '/../templates/navigation/navigation.php';
    echo '<div class="container mt-4 alert alert-danger">Chantier introuvable pour cette entreprise.</div>';
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

/* Droits d’accès : admin OK, chef si assigné à ce chantier */
$allowed = false;
if ($role === 'administrateur') {
    $allowed = true;
} elseif ($role === 'chef') {
    $stmtAuth = $pdo->prepare("
    SELECT 1 FROM utilisateur_chantiers
    WHERE utilisateur_id = :uid AND chantier_id = :cid AND entreprise_id = :eid
    LIMIT 1
  ");
    $stmtAuth->execute([':uid' => (int)$user['id'], ':cid' => $chantierId, ':eid' => $entrepriseId]);
    $allowed = (bool)$stmtAuth->fetchColumn();
}
if (!$allowed) {
    require_once __DIR__ . '/../templates/header.php';
    require_once __DIR__ . '/../templates/navigation/navigation.php';
    echo '<div class="container mt-4 alert alert-danger">Accès refusé.</div>';
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/navigation/navigation.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

<style>
    .chantier-card {
        transition: transform .15s ease, box-shadow .15s ease;
        border-radius: 1rem;
    }

    .chantier-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 .5rem 1rem rgba(0, 0, 0, .15);
    }

    .card-icon {
        font-size: 4rem;
        line-height: 1;
    }

    a.stretched {
        text-decoration: none;
        color: inherit;
    }
</style>

<div class="container my-4">
    <a href="javascript:history.back()" class="btn btn-outline-secondary">← Retour</a>
    <h1 class="text-center mb-1">Chantier : <?= htmlspecialchars($chantier['nom']) ?></h1>
    <p class="text-center text-muted mb-5">Choisissez un module</p>

    <div class="row g-4 justify-content-center">
        <!-- Card Stock -->
        <div class="col-12 col-sm-6 col-lg-4">
            <a class="stretched" href="chantier_contenu.php?id=<?= (int)$chantier['id'] ?>">
                <div class="card chantier-card text-center p-4 shadow-sm">
                    <div class="card-icon text-primary"><i class="bi bi-box-seam"></i></div>
                    <h4 class="mt-3 mb-1">Stock</h4>
                    <p class="text-muted mb-0">Consulter et transférer le matériel de ce chantier</p>
                </div>
            </a>
        </div>

        <!-- Card Heures -->
        <div class="col-12 col-sm-6 col-lg-4">
            <a class="stretched" href="chantier_heures.php?id=<?= (int)$chantier['id'] ?>">
                <div class="card chantier-card text-center p-4 shadow-sm">
                    <div class="card-icon text-success"><i class="bi bi-clock-history"></i></div>
                    <h4 class="mt-3 mb-1">Heures</h4>
                    <p class="text-muted mb-0">Suivi des heures par tâche</p>
                </div>
            </a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>