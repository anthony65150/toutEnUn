<?php


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
        if (empty($utilisateurs["chantiers"]) || !is_array($utilisateurs["chantiers"])) {
            $errors["chantiers"] = 'Il faut sélectionner au moins un chantier';
        }
    }

    return empty($errors) ? true : $errors;
}

function verifyUserLoginPassword(PDO $pdo, string $email, string $motDePasse): bool|array
{
    $query = $pdo->prepare("SELECT id, nom, prenom, email, photo, motDePasse, fonction FROM utilisateurs WHERE email = :email");

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
