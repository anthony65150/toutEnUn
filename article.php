<?php
require_once "./config/init.php";

if (!isset($_SESSION['utilisateurs']) || $_SESSION['utilisateurs']['fonction'] !== 'administrateur') {
    header("Location: connexion.php");
    exit;
}

require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/navigation/navigation.php';

// Vérifie qu'un ID est passé dans l'URL
if (!isset($_GET['id'])) {
    echo "<div class='container mt-4 alert alert-danger'>Aucun article sélectionné.</div>";
    require_once __DIR__ . '/templates/footer.php';
    exit;
}

$articleId = (int) $_GET['id'];

// 🔍 1. Récupération des infos de l’article
$stmt = $pdo->prepare("
    SELECT * FROM stock WHERE id = ?
");


$stmt->execute([$articleId]);
$article = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$article) {
    echo "<div class='container mt-4 alert alert-warning'>Article introuvable.</div>";
    require_once __DIR__ . '/templates/footer.php';
    exit;
}

// Quantité en dépôt
$stmt = $pdo->prepare("SELECT SUM(quantite) FROM stock_depots WHERE stock_id = ?");
$stmt->execute([$articleId]);
$quantiteDepot = (int) $stmt->fetchColumn();

// Quantité sur chantiers
$stmt = $pdo->prepare("SELECT SUM(quantite) FROM stock_chantiers WHERE stock_id = ?");
$stmt->execute([$articleId]);
$quantiteChantier = (int) $stmt->fetchColumn();

// Total
$totalQuantite = $quantiteDepot + $quantiteChantier;



?>

<div class="container mt-4">
    <!-- En-tête article -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="stock_admin.php" class="btn btn-outline-secondary">
            ← Retour
        </a>
        <div>
            <h2 class="mb-0 fw-bold"><?= ucfirst(htmlspecialchars(strtolower($article['nom']))) ?></h2>

            <small class="text-muted">
                Catégorie : <?= htmlspecialchars($article['categorie'] ?? '-') ?> > <?= htmlspecialchars($article['sous_categorie'] ?? '-') ?>
            </small>


        </div>
    </div>

    <!-- Carte infos principales -->
    <div class="row g-4 mb-4">
        <!-- Image -->
        <div class="col-md-4">
            <div class="card shadow-sm">
                <img src="uploads/etai.jpg" class="card-img-top" alt="Photo de l'article">
            </div>
        </div>

        <!-- Quantités -->
        <div class="col-md-8">
            <div class="card shadow-sm p-3">
                <h5 class="fw-bold mb-3">Quantités disponibles</h5>
                <div class="row text-center">
                    <div class="col">
                        <div class="bg-light p-3 rounded-2">
                            <div class="fw-bold fs-4"><?= $totalQuantite ?></div>
                            <div class="text-muted">Total</div>

                        </div>
                    </div>
                    <div class="col">
                        <div class="bg-light p-3 rounded-2">
                            <div class="fw-bold fs-4"><?= $quantiteChantier ?></div>
                            <div class="text-muted">Sur chantiers</div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="bg-light p-3 rounded-2">
                            <div class="fw-bold fs-4"><?= $quantiteDepot ?></div>
                            <div class="text-muted">En dépôt</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Onglets -->
    <ul class="nav nav-tabs mb-3" id="articleTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="details-tab" data-bs-toggle="tab" data-bs-target="#details" type="button" role="tab">Détails</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="files-tab" data-bs-toggle="tab" data-bs-target="#files" type="button" role="tab">Fichiers</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab">Historique</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="actions-tab" data-bs-toggle="tab" data-bs-target="#actions" type="button" role="tab">Actions</button>
        </li>
    </ul>

    <div class="tab-content" id="articleTabContent">
        <!-- Détails -->
        <div class="tab-pane fade show active" id="details" role="tabpanel">
            <div class="card shadow-sm p-3">
                <h5 class="fw-bold">Caractéristiques de l'article</h5>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item">Référence : <?= htmlspecialchars($article['reference'] ?? '-') ?></li>
                    <li class="list-group-item">Dimensions : <?= htmlspecialchars($article['dimensions'] ?? '-') ?></li>
                    <li class="list-group-item">Poids : <?= htmlspecialchars($article['poids'] ?? '-') ?></li>
                    <li class="list-group-item">Matériau : <?= htmlspecialchars($article['materiau'] ?? '-') ?></li>
                    <li class="list-group-item">Fournisseur : <?= htmlspecialchars($article['fournisseur'] ?? '-') ?></li>
                </ul>

                <hr>
                <h6 class="fw-bold mt-3">Répartition par chantier</h6>
                <ul class="list-group list-group-flush">
                    <?php if (count($quantitesParChantier) > 0): ?>
                        <?php foreach ($quantitesParChantier as $chantier): ?>
                            <li class="list-group-item">
                                <?= htmlspecialchars($chantier['chantier_nom']) ?> :
                                <strong><?= (int)$chantier['quantite'] ?></strong> unité(s)
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="list-group-item text-muted">Aucune quantité sur les chantiers</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <!-- Fichiers -->
        <div class="tab-pane fade" id="files" role="tabpanel">
            <div class="card shadow-sm p-3">
                <h5 class="fw-bold">Fichiers techniques</h5>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        📄 Fiche_technique.pdf
                        <div>
                            <a href="#" class="btn btn-sm btn-outline-primary me-2">Télécharger</a>
                            <button class="btn btn-sm btn-outline-danger">🗑️</button>
                        </div>
                    </li>
                    <!-- Autres fichiers ici -->
                </ul>
                <div class="mt-3">
                    <button class="btn btn-outline-success">+ Ajouter un fichier</button>
                </div>
            </div>
        </div>

        <!-- Historique -->
        <div class="tab-pane fade" id="history" role="tabpanel">
            <div class="card shadow-sm p-3">
                <h5 class="fw-bold">Historique des actions</h5>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item">27/07 - Transfert de 10 unités vers Chantier A</li>
                    <li class="list-group-item">25/07 - Ajout d’un fichier PDF</li>
                    <li class="list-group-item">24/07 - Article créé par Anthony</li>
                </ul>
            </div>
        </div>

        <!-- Actions (admin uniquement, à activer si besoin) -->

        <div class="tab-pane fade" id="actions" role="tabpanel">
            <div class="card shadow-sm p-3">
                <h5 class="fw-bold">Actions rapides</h5>
                <button class="btn btn-primary me-2">✏️ Modifier</button>
                <button class="btn btn-danger me-2">🗑️ Supprimer</button>
                <button class="btn btn-secondary">🔄 Transférer</button>
            </div>
        </div>

    </div>
</div>


<?php require_once __DIR__ . '/templates/footer.php'; ?>