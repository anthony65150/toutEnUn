<?php
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

// Pour récupérer les anciennes données en cas de mise à jour réussie
$nom = $user['nom'] ?? '';
$prenom = $user['prenom'] ?? '';
$email = $user['email'] ?? '';
$photoPath = $user['photo'] ?? 'images/anthony.jpg';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nom = trim($_POST['nom']);
    $prenom = trim($_POST['prenom']);
    $email = trim($_POST['email']);
    $motDePasse = trim($_POST['motDePasse']);
    $photo = $_FILES['photo'] ?? null;

    // Traitement de la photo si fournie
    if ($photo && $photo['error'] === 0) {
        $uploadDir = __DIR__ . '/uploads/photos/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $photoName = uniqid() . '_' . basename($photo['name']);
        move_uploaded_file($photo['tmp_name'], $uploadDir . $photoName);
        $photoPath = '/uploads/photos/' . $photoName;
    }

    // Requête SQL
    $stmt = $pdo->prepare("UPDATE utilisateurs SET nom = ?, prenom = ?, email = ?, motDePasse = ?, photo = ? WHERE id = ?");
    $motDePasseToSave = !empty($motDePasse) ? password_hash($motDePasse, PASSWORD_DEFAULT) : $user['motDePasse'];
    $result = $stmt->execute([
        $nom,
        $prenom,
        $email,
        $motDePasseToSave,
        $photoPath,
        $user['id']
    ]);


    if ($result) {
        // Mise à jour de la session
        $_SESSION['utilisateurs']['nom'] = $nom;
        $_SESSION['utilisateurs']['prenom'] = $prenom;
        $_SESSION['utilisateurs']['email'] = $email;
        $_SESSION['utilisateurs']['photo'] = $photoPath;
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
            src="<?= htmlspecialchars($user['photo'] ?? 'images/anthony.jpg') ?>"
            alt="Photo de profil"
            class="rounded-circle"
            style="width: 100px; height: 100px; object-fit: cover;">
    </div>

    <div class="mb-3">
        <label class="form-label">Nom</label>
        <input type="text" name="nom" class="form-control" value="<?= htmlspecialchars($user["nom"]) ?>" required>
    </div>

    <div class="mb-3">
        <label class="form-label">Prénom</label>
        <input type="text" name="prenom" class="form-control" value="<?= htmlspecialchars($user['prenom']) ?>" required>
    </div>

    <div class="mb-3">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" required>
    </div>

    <div class="mb-3">
        <label class="form-label">Nouveau mot de passe</label>
        <input type="password" name="motDePasse" class="form-control" placeholder="Laisser vide pour ne pas changer">
    </div>

    <div class="mb-3">
        <label class="form-label">Photo de profil</label>
        <input type="file" name="photo" class="form-control" accept="image/*" onchange="previewPhoto(event)">
    </div>

    <button type="submit" class="btn btn-primary w-100">Mettre à jour</button>
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
