<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>


<!-- Menu complet affiché uniquement au-dessus de md -->
<nav class="d-none d-md-block fond-gris border-bottom">
    <div class="container d-flex justify-content-center">
        <ul class="nav nav-pills">
            <li class="nav-item p-2">
                <a href="/index.php" class="nav-link <?php echo ($current_page == 'index.php') ? 'active' : ''; ?>">
                    Accueil
                </a>
            </li>
            <li class="p-2">
                <a href="/mes_documents.php" class="nav-link <?php echo ($current_page == '/mes_documents.php') ? 'active' : ''; ?> text-center">
                    Mes documents
                </a>
            </li>
            <li class="p-2">
                <a href="/mes_conges.php" class="nav-link <?php echo ($current_page == '/mes_conges.php') ? 'active' : ''; ?> text-center">
                    Mes congés
                </a>
            </li>
            <li class="p-2">
                <a href="/mon_pointage.php" class="nav-link <?php echo ($current_page == '/mon_pointage.php') ? 'active' : ''; ?> text-center">
                    Mon pointage
                </a>
            </li>
            <li class="p-2">
                <a href="/autres_demandes.php" class="nav-link <?php echo ($current_page == '/autres_demandes.php') ? 'active' : ''; ?> text-center">
                    Autres demandes
                </a>
            </li>
            <?php if (isset($_SESSION['utilisateurs']['fonction']) && ($_SESSION['utilisateurs']['fonction'] === 'administrateur' || $_SESSION['utilisateurs']['fonction'] === 'chef')) : ?>
                <li class="p-2">
                    <a href="/admin/pointage.php" class="nav-link <?php echo ($current_page == 'pointage.php') ? 'active' : ''; ?> text-center">
                        Pointage
                    </a>
                </li>
            <?php endif; ?>
            <?php if (isset($_SESSION['utilisateurs']['fonction']) && $_SESSION['utilisateurs']['fonction'] === 'administrateur') : ?>
                <li class="p-2">
                    <a href="/ajoutEmploye.php" class="nav-link <?php echo ($current_page == 'ajoutEmploye.php') ? 'active' : ''; ?> text-center">
                        Ajout employés
                    </a>
                </li>
            <?php endif; ?>
            <?php if (isset($_SESSION['utilisateurs']['fonction']) && ($_SESSION['utilisateurs']['fonction'] === 'administrateur' || $_SESSION['utilisateurs']['fonction'] === 'chef')) : ?>
                <li class="p-2">
                    <a href="/stock.php" class="nav-link <?php echo ($current_page == 'stock.php') ? 'active' : ''; ?> text-center">
                        Stock
                    </a>
                </li>
            <?php endif; ?>
            <?php if (isset($_SESSION["utilisateurs"])) { ?>
                <li class="p-2">
                    <a class="nav-link text-danger" href="/deconnexion.php">Déconnexion</a>
                </li>
            <?php } ?>
        </ul>
    </div>
</nav>