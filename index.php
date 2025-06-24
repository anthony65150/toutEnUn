<?php
require_once "./config/init.php";
require_once "templates/header.php";
require_once "templates/navigation/navigation.php";

// Si utilisateur non connecté, rediriger vers connexion.php
if (!isset($_SESSION['utilisateurs'])) {
    header('Location: connexion.php');
    exit;
}



// Récupérer le chemin de la page actuelle
$current_page = $_SERVER['PHP_SELF'];
?>








<?php
require_once "templates/footer.php"
?>