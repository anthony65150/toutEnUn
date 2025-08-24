<?php
// À placer AVANT ce bloc (dans header.php par exemple)
$current_page = basename($_SERVER['PHP_SELF']);
?>

<?php if ($current_page !== 'connexion.php'): ?>
    <!-- Menu burger uniquement visible en dessous de md -->
    <div class="collapse navbar-collapse d-md-none fond-gris text-center" id="navbarBurgerMenu">
        <ul class="navbar-nav ms-auto mb-2 mb-md-0 d-md-none">
            <li class="nav-item p-2">
                <a href="/accueil.php" class="nav-link <?= ($current_page == 'accueil.php') ? 'active' : '' ?>">
                    Accueil
                </a>
            </li>

            <li class="p-2">
                <a href="/mes_documents.php" class="nav-link <?= ($current_page == 'mes_documents.php') ? 'active' : '' ?> text-center">
                    Mes documents
                </a>
            </li>

            <li class="p-2">
                <a href="/mes_conges.php" class="nav-link <?= ($current_page == 'mes_conges.php') ? 'active' : '' ?> text-center">
                    Mes congés
                </a>
            </li>

            <li class="p-2">
                <a href="/mon_pointage.php" class="nav-link <?= ($current_page == 'mon_pointage.php') ? 'active' : '' ?> text-center">
                    Mon pointage
                </a>
            </li>

            <li class="p-2">
                <a href="/autres_demandes.php" class="nav-link <?= ($current_page == 'autres_demandes.php') ? 'active' : '' ?> text-center">
                    Autres demandes
                </a>
            </li>

            <?php if (in_array($_SESSION['utilisateurs']['fonction'] ?? '', ['administrateur', 'chef'])) : ?>
                <li class="p-2">
                    <a href="/pointage.php" class="nav-link <?= ($current_page == 'pointage.php') ? 'active' : '' ?> text-center">
                        Pointage
                    </a>
                </li>
            <?php endif; ?>

            <?php if ($_SESSION['utilisateurs']['fonction'] ?? '' === 'administrateur') : ?>
                <li class="p-2">
                    <a href="/chantiers_admin.php" class="nav-link <?= ($current_page == 'chantiers_admin.php') ? 'active' : '' ?> text-center">
                        Chantiers
                    </a>
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



            <?php
            $fonction = $_SESSION['utilisateurs']['fonction'] ?? '';
            $stockPage = match ($fonction) {
                'administrateur' => 'stock_admin.php',
                'chef' => 'stock_chef.php',
                'depot' => 'stock_depot.php',
                default => null
            };
            ?>
            <?php if ($_SESSION['utilisateurs']['fonction'] === 'administrateur'): ?>
                <li class="nav-item p-2">
                    <a class="nav-link" href="employes.php">
                        Employés
                    </a>
                </li>
            <?php endif; ?>
            
            <?php if ($stockPage): ?>
                <li class="nav-item p-2">
                    <a href="/<?= $stockPage ?>" class="nav-link <?= ($current_page == $stockPage) ? 'active' : '' ?> text-center">
                        Stock
                    </a>
                </li>
            <?php endif; ?>


            <?php if (isset($_SESSION["utilisateurs"])): ?>
                <li class="p-2">
                    <a class="nav-link text-danger" href="/deconnexion.php">Déconnexion</a>
                </li>
            <?php endif; ?>
        </ul>
    </div>
<?php endif; ?>