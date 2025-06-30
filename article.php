<?php
require_once "./config/init.php";
require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/navigation/navigation.php';

if (!isset($_SESSION['utilisateurs'])) {
    header("Location: connexion.php");
    exit;
}

// Simulation des articles (normalement à remplacer par une requête SQL)
$stocks = [
    'Étai 2m50' => [
        'nom' => 'Étai 2m50',
        'total' => 36,
        'disponible' => 20,
        'chantier' => 'Chantier A',
        'categorie' => 'Étais',
        'sous_categorie' => 'Étais métalliques'
    ],
    'Banche 3m' => [
        'nom' => 'Banche 3m',
        'total' => 12,
        'disponible' => 4,
        'chantier' => 'Chantier B',
        'categorie' => 'Banches',
        'sous_categorie' => 'Banches bois'
    ]
];

// Récupération de l'article depuis l'URL
$articleId = isset($_GET['id']) ? urldecode($_GET['id']) : null;

if (!$articleId || !isset($stocks[$articleId])) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Article introuvable.</div></div>";
    require_once __DIR__ . '/templates/footer.php';
    exit;
}

$article = $stocks[$articleId];
?>

<div class="container py-5">
    <h2 class="text-center mb-4"><?= htmlspecialchars($article['nom']) ?></h2>

    <div class="row justify-content-center">
        <div class="col-md-6">
            <ul class="list-group">
                <li class="list-group-item"><strong>Catégorie :</strong> <?= htmlspecialchars($article['categorie']) ?></li>
                <li class="list-group-item"><strong>Sous-catégorie :</strong> <?= htmlspecialchars($article['sous_categorie']) ?></li>
                <li class="list-group-item"><strong>Chantier :</strong> <?= htmlspecialchars($article['chantier']) ?></li>
                <li class="list-group-item"><strong>Quantité totale :</strong> <?= $article['total'] ?></li>
                <li class="list-group-item text-success"><strong>Disponible :</strong> <?= $article['disponible'] ?></li>
                <li class="list-group-item text-warning"><strong>Sur chantier :</strong> <?= $article['total'] - $article['disponible'] ?></li>
            </ul>
        </div>
    </div>

    <div class="text-center mt-4">
        <a href="stock.php" class="btn btn-secondary">Retour au stock</a>
    </div>
</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
