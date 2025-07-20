<?php
require_once "./config/init.php";

if (!isset($_SESSION['utilisateurs']) || $_SESSION['utilisateurs']['fonction'] !== 'administrateur') {
    header('Location: connexion.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chantierId = $_POST['chantier_id'] ?? '';
    $nom = trim($_POST['nom'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $dateDebut = $_POST['date_debut'] ?: null;
    $dateFin = $_POST['date_fin'] ?: null;
    $responsableId = $_POST['responsable_id'] ?? null;

    if ($nom && $responsableId) {
        if ($chantierId) {
            // Modifier
            $stmt = $pdo->prepare("UPDATE chantiers SET nom = ?, description = ?, date_debut = ?, date_fin = ?, responsable_id = ? WHERE id = ?");
            $stmt->execute([$nom, $description, $dateDebut, $dateFin, $responsableId, $chantierId]);
            $_SESSION['flash'] = "Chantier modifié avec succès.";
        } else {
            // Ajouter
            $stmt = $pdo->prepare("INSERT INTO chantiers (nom, description, date_debut, date_fin, responsable_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$nom, $description, $dateDebut, $dateFin, $responsableId]);
            $_SESSION['flash'] = "Chantier ajouté avec succès.";
        }
    } else {
        $_SESSION['flash'] = "Erreur : nom et chef requis.";
    }

    header('Location: chantiers_admin.php');
    exit;
}
