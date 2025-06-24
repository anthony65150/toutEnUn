<?php
require_once "./config/init.php";
require_once __DIR__ . '/templates/header.php';
require_once "templates/navigation/navigation.php";
require_once __DIR__ . '/fonctions/utilisateurs.php';

if (!isset($_SESSION['utilisateurs'])) {
    header("Location: connexion.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE id = ?");
$stmt->execute([$_SESSION['utilisateurs']['id']]);
$user = $stmt->fetch();

// Mets aussi à jour la session avec les vraies données de la base (optionnel mais conseillé)
$_SESSION['utilisateurs'] = $user;

$errors = [];
$success = "";

// Pour récupérer les anciennes données
$nom = $user['nom'] ?? '';
$prenom = $user['prenom'] ?? '';
$email = $user['email'] ?? '';
$photoActuelle = $user['photo'] ?? '/images/image-default.png';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nom = trim($_POST['nom']);
    $prenom = trim($_POST['prenom']);
    $email = trim($_POST['email']);
    $motDePasse = trim($_POST['motDePasse']);
    $photo = $_FILES['photo'] ?? null;
    $nouvellePhoto = $photoActuelle;

    // Traitement de la photo si fournie
    if ($photo && $photo['error'] === 0) {
        $uploadDir = __DIR__ . '/uploads/photos/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Supprimer l’ancienne photo si ce n’est pas la photo par défaut
        $cheminPhotoActuelle = $_SERVER['DOCUMENT_ROOT'] . $photoActuelle;
        if (!empty($photoActuelle) && file_exists($cheminPhotoActuelle) && $photoActuelle !== '/images/image-default.png') {
            unlink($cheminPhotoActuelle);
        }

        // Enregistrer la nouvelle photo
        $photoName = uniqid() . '_' . basename($photo['name']);
        move_uploaded_file($photo['tmp_name'], $uploadDir . $photoName);
        $nouvellePhoto = '/uploads/photos/' . $photoName;
    }

    // Hachage du mot de passe uniquement s’il est rempli
    $motDePasseToSave = !empty($motDePasse) ? password_hash($motDePasse, PASSWORD_DEFAULT) : $user['motDePasse'];

    // Mise à jour de la base
    $stmt = $pdo->prepare("UPDATE utilisateurs SET nom = ?, prenom = ?, email = ?, motDePasse = ?, photo = ? WHERE id = ?");
    $result = $stmt->execute([
        $nom,
        $prenom,
        $email,
        $motDePasseToSave,
        $nouvellePhoto,
        $user['id']
    ]);

    if ($result) {
        // Mise à jour de la session
        $_SESSION['utilisateurs']['nom'] = $nom;
        $_SESSION['utilisateurs']['prenom'] = $prenom;
        $_SESSION['utilisateurs']['email'] = $email;
        $_SESSION['utilisateurs']['photo'] = $nouvellePhoto;
        $_SESSION['utilisateurs']['motDePasse'] = $motDePasseToSave;
        $success = "Profil mis à jour avec succès.";
    } else {
        $errors[] = "Une erreur est survenue lors de la mise à jour.";
    }
}
?>

<div class="container py-5">
    <h2 class="text-center mb-4">Mon profil</h2>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endforeach; ?>

    <form method="POST" enctype="multipart/form-data" class="mx-auto" style="max-width: 1000px;">
        <div class="text-center mb-3">
            <img id="preview-photo"
                src="<?= htmlspecialchars($_SESSION['utilisateurs']['photo'] ?? '/images/image-default.png') ?>"
                alt="Photo de profil"
                class="rounded-circle"
                style="width: 100px; height: 100px; object-fit: cover;">
        </div>

        <div class="mb-3">
            <label class="form-label">Nom</label>
            <input type="text" name="nom" class="form-control" value="<?= htmlspecialchars($nom) ?>" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Prénom</label>
            <input type="text" name="prenom" class="form-control" value="<?= htmlspecialchars($prenom) ?>" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($email) ?>" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Nouveau mot de passe</label>
            <input type="password" name="motDePasse" class="form-control" placeholder="Laisser vide pour ne pas changer">
        </div>

        <div class="mb-3">
            <label class="form-label">Photo de profil</label>
            <input type="file" name="photo" class="form-control" accept="image/*" onchange="previewPhoto(event)">
        </div>

        <div class="text-center mt-5">
            <button type="submit" class="btn btn-primary w-50">Mettre à jour</button>
        </div>
    </form>

    <script>
        function previewPhoto(event) {
            const img = document.getElementById('preview-photo');
            const file = event.target.files[0];
            if (file) {
                img.src = URL.createObjectURL(file);
            }
        }
    </script>
</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>