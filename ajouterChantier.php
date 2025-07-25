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
            // Modifier le chantier
            $stmt = $pdo->prepare("UPDATE chantiers SET nom = ?, description = ?, date_debut = ?, date_fin = ?, responsable_id = ? WHERE id = ?");
            $stmt->execute([$nom, $description, $dateDebut, $dateFin, $responsableId, $chantierId]);

            // 🔁 Supprimer les anciens liens (si on veut gérer les changements de chef)
            $pdo->prepare("DELETE FROM utilisateur_chantiers WHERE chantier_id = ?")->execute([$chantierId]);

            // 🆕 Réinsérer le lien
            $pdo->prepare("INSERT INTO utilisateur_chantiers (utilisateur_id, chantier_id) VALUES (?, ?)")->execute([$responsableId, $chantierId]);

            $_SESSION['flash'] = "Chantier modifié avec succès.";
        } else {
            // Ajouter un nouveau chantier
            $stmt = $pdo->prepare("INSERT INTO chantiers (nom, description, date_debut, date_fin, responsable_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$nom, $description, $dateDebut, $dateFin, $responsableId]);

            $newChantierId = $pdo->lastInsertId();

            // Lier au chef de chantier dans la table utilisateur_chantiers
            $pdo->prepare("INSERT INTO utilisateur_chantiers (utilisateur_id, chantier_id) VALUES (?, ?)")->execute([$responsableId, $newChantierId]);

            $_SESSION['flash'] = "Chantier ajouté avec succès.";
        }
    } else {
        $_SESSION['flash'] = "Erreur : nom et chef requis.";
    }

    header('Location: chantiers_admin.php');
    exit;
}
