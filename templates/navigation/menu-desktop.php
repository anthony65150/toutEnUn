<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!-- Menu complet affiché uniquement au-dessus de md -->
<nav class="d-none d-md-block fond-gris border-bottom">
    <div class="container d-flex justify-content-center">
        <ul class="nav nav-pills">
            <li class="nav-item p-2">
                <a href="/accueil.php" class="nav-link <?php echo ($current_page == 'accueil.php') ? 'active' : ''; ?>">Accueil</a>
            </li>
            <li class="nav-item p-2">
                <a href="/mes_documents.php" class="nav-link <?php echo ($current_page == 'mes_documents.php') ? 'active' : ''; ?>">Mes documents</a>
            </li>
            <li class="nav-item p-2">
                <a href="/mes_conges.php" class="nav-link <?php echo ($current_page == 'mes_conges.php') ? 'active' : ''; ?>">Mes congés</a>
            </li>
            <li class="nav-item p-2">
                <a href="/mon_pointage.php" class="nav-link <?php echo ($current_page == 'mon_pointage.php') ? 'active' : ''; ?>">Mon pointage</a>
            </li>
            <li class="nav-item p-2">
                <a href="/autres_demandes.php" class="nav-link <?php echo ($current_page == 'autres_demandes.php') ? 'active' : ''; ?>">Autres demandes</a>
            </li>

            <?php if (isset($_SESSION['utilisateurs']['fonction']) && in_array($_SESSION['utilisateurs']['fonction'], ['administrateur', 'chef'])) : ?>
                <li class="nav-item p-2">
                    <a href="/pointage.php" class="nav-link <?php echo ($current_page == 'pointage.php') ? 'active' : ''; ?>">Pointage</a>
                </li>
            <?php endif; ?>

            <?php if (isset($_SESSION['utilisateurs']['fonction']) && $_SESSION['utilisateurs']['fonction'] === 'administrateur') : ?>
                <li class="nav-item p-2">
                    <a href="/chantiers_admin.php" class="nav-link <?php echo ($current_page == 'chantiers_admin.php') ? 'active' : ''; ?>">Chantiers</a>
                </li>
            <?php endif; ?>

            <?php if ($_SESSION['utilisateurs']['fonction'] === 'administrateur'): ?>
                <li class="nav-item p-2">
                    <a href="/depots_admin.php"
                        class="nav-link <?php echo ($current_page == 'depots_admin.php') ? 'active' : ''; ?>">
                        Dépôts
                    </a>
                </li>
            <?php endif; ?>

            <?php if ($_SESSION['utilisateurs']['fonction'] === 'administrateur'): ?>
                <li class="nav-item p-2">
                    <a class="nav-link" href="employes.php">
                        Employés
                    </a>
                </li>
            <?php endif; ?>




            <?php
            $fonction = $_SESSION['utilisateurs']['fonction'] ?? '';
            $stockPage = match ($fonction) {
                'administrateur' => 'stock_admin.php',
                'chef' => 'stock_chef.php',
                'depot' => 'stock_depot.php',
                default => null
            };
            ?>
            <?php if ($stockPage): ?>
                <li class="nav-item p-2">
                    <a href="/<?= $stockPage ?>" class="nav-link <?php echo ($current_page == $stockPage) ? 'active' : ''; ?>">Stock</a>
                </li>
            <?php endif; ?>
        </ul>


    </div>
</nav>