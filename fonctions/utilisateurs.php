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
    // ajoute entreprise_id (et actif si tu l'as)
    $sql = "SELECT id, nom, prenom, email, photo, motDePasse, fonction, entreprise_id
            FROM utilisateurs
            WHERE email = :email /* AND actif = 1 */";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(":email", $email);
    $stmt->execute();

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($motDePasse, $user["motDePasse"])) {
        unset($user["motDePasse"]);
        return $user; // contient maintenant entreprise_id
    }
    return false;
}

