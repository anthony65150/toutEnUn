<?php
require_once "./config/init.php";

if (!isset($_SESSION['utilisateurs']) || $_SESSION['utilisateurs']['fonction'] !== 'administrateur') {
    header('Location: connexion.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $chantierId = (int)$_POST['delete_id'];

    // Supprimer les liens utilisateurs ↔ chantier
    $pdo->prepare("DELETE FROM utilisateur_chantiers WHERE chantier_id = ?")->execute([$chantierId]);

    // Supprimer le chantier
    $pdo->prepare("DELETE FROM chantiers WHERE id = ?")->execute([$chantierId]);

    $_SESSION['flash'] = "Chantier supprimé avec succès.";
    header("Location: chantiers_admin.php?success=delete");
    exit;
}
?>
