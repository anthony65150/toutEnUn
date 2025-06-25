<?php
require_once "./config/init.php";
require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/navigation/navigation.php';

if (!isset($_SESSION['utilisateurs'])) {
    header("Location: connexion.php");
    exit;
}
?>

<div class="container py-5">
    <div class="container py-2">
        <h2 class="text-center mb-5">Gestion de stock</h2>

        <!-- Slide horizontal de catégories -->
        <div class="overflow-auto pb-2">
            <div id="categoriesSlide" class="d-flex flex-nowrap gap-3 justify-content-center flex-wrap-nowrap">
                <button class="btn btn-outline-primary flex-shrink-0">Étais</button>
                <button class="btn btn-outline-primary flex-shrink-0">Banches</button>
                <button class="btn btn-outline-primary flex-shrink-0">Madriers</button>
                <button class="btn btn-outline-primary flex-shrink-0">Planches</button>
                <button class="btn btn-outline-primary flex-shrink-0">Coffrages</button>
                <button class="btn btn-outline-primary flex-shrink-0">Échelles</button>
                <button class="btn btn-outline-primary flex-shrink-0">Niveaux</button>
                <button class="btn btn-outline-primary flex-shrink-0">Autres</button>
            </div>
        </div>

        <!-- Sous-catégories - filtres -->
        <div id="subCategoriesSlide" class="overflow-auto pb-2 mt-3 d-flex gap-3 justify-content-center flex-nowrap"></div>
    </div>

    <!-- Champ de recherche -->
    <div class="mb-4">
        <input type="text" id="searchInput" class="form-control" placeholder="Rechercher un article (étai, madrier, etc.)">
    </div>

    <!-- Bouton Ajouter -->
    <div class="mb-4 text-end">
        <a href="ajouterStock.php" class="btn btn-success">
            <i class="bi bi-plus-circle"></i> Ajouter un article
        </a>
    </div>

    <!-- Tableau du stock -->
    <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle">
            <thead class="table-dark text-center">
                <tr>
                    <th>Nom</th>
                    <th>Quantité</th>
                    <th>Emplacement</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="stockTableBody">
                <!-- Exemple de ligne temporaire avec data-cat et data-subcat -->
                <tr data-cat="Étais" data-subcat="Étais métalliques">
                    <td>Étai 2m50</td>
                    <td class="text-center">12</td>
                    <td>Chantier A</td>
                    <td class="text-center">
                        <a href="#" class="btn btn-sm btn-warning"><i class="bi bi-pencil"></i></a>
                        <a href="#" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></a>
                    </td>
                </tr>
                <tr data-cat="Banches" data-subcat="Banches bois">
                    <td>Banche 3m</td>
                    <td class="text-center">8</td>
                    <td>Chantier B</td>
                    <td class="text-center">
                        <a href="#" class="btn btn-sm btn-warning"><i class="bi bi-pencil"></i></a>
                        <a href="#" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></a>
                    </td>
                </tr>
                <!-- Ajoute ici d'autres lignes avec data-cat et data-subcat -->
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>

<script src="/js/stock.js"></script>
