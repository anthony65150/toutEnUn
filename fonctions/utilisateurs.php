<?php
function ajouUtilisateur(PDO $pdo, string $nom, string $prenom, string $email, string $motDePasse, string $fonction, int $chantier_id = null): bool
{
    $query = $pdo->prepare("INSERT INTO utilisateurs (nom, prenom, email, motDePasse, fonction, chantier_id) 
                            VALUES (:nom, :prenom, :email, :motDePasse, :fonction, :chantier_id)");

    $motDePasse = password_hash($motDePasse, PASSWORD_DEFAULT);

    $query->bindValue(':nom', $nom);
    $query->bindValue(':prenom', $prenom);
    $query->bindValue(':email', $email);
    $query->bindValue(':motDePasse', $motDePasse);
    $query->bindValue(':fonction', $fonction);
    $query->bindValue(':chantier_id', $chantier_id, PDO::PARAM_INT);

    $success = $query->execute();

    if ($success && $fonction === 'chef' && $chantier_id !== null) {
        $newUserId = $pdo->lastInsertId();
        $update = $pdo->prepare("UPDATE chantiers SET responsable_id = :id WHERE id = :chantier_id");
        $update->execute([
            ':id' => $newUserId,
            ':chantier_id' => $chantier_id
        ]);
    }

    if ($success && $fonction === 'depot') {
        $utilisateurId = $pdo->lastInsertId();
        $stmt = $pdo->prepare("UPDATE depots SET responsable_id = ?");
        $stmt->execute([$utilisateurId]);
    }

    return $success;
}

function verifieUtilisateur(array $utilisateurs): array|bool
{
    $errors = [];

    if (empty($utilisateurs["nom"])) {
        $errors["nom"] = 'Le champ "Nom" est obligatoire';
    }

    if (empty($utilisateurs["prenom"])) {
        $errors["prenom"] = 'Le champ "Prénom" est obligatoire';
    }

    if (empty($utilisateurs["email"])) {
        $errors["email"] = 'Le champ "Email" est obligatoire';
    } elseif (!filter_var($utilisateurs["email"], FILTER_VALIDATE_EMAIL)) {
        $errors["email"] = "Le format d'email n'est pas respecté";
    }

    if (empty($utilisateurs["motDePasse"])) {
        $errors["motDePasse"] = 'Le champ "Mot de passe" est obligatoire';
    }

    if (empty($utilisateurs["fonction"])) {
        $errors["fonction"] = 'Le champ "Fonction" est obligatoire';
    }

    if ($utilisateurs["fonction"] === "chef") {
        if (!isset($utilisateurs["chantier_id"]) || $utilisateurs["chantier_id"] === "") {
            $errors["chantier_id"] = 'Le champ "Chantier" est obligatoire pour un chef de chantier';
        }
    }

    return empty($errors) ? true : $errors;
}

function verifyUserLoginPassword(PDO $pdo, string $email, string $motDePasse): bool|array
{
    $query = $pdo->prepare("SELECT id, nom, prenom, email, photo, motDePasse, fonction, chantier_id 
                            FROM utilisateurs 
                            WHERE email = :email");
    $query->bindValue(":email", $email);
    $query->execute();

    $utilisateurs = $query->fetch(PDO::FETCH_ASSOC);



    if ($utilisateurs && password_verify($motDePasse, $utilisateurs["motDePasse"])) {
        unset($utilisateurs["motDePasse"]);
        return $utilisateurs;
    } else {
        return false;
    }
}
?>
