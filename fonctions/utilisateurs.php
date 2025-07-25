<?php
function ajouUtilisateur(PDO $pdo, string $nom, string $prenom, string $email, string $motDePasse, string $fonction, ?array $chantiers = []): bool
{
    $query = $pdo->prepare("INSERT INTO utilisateurs (nom, prenom, email, motDePasse, fonction) 
                            VALUES (:nom, :prenom, :email, :motDePasse, :fonction)");

    $motDePasse = password_hash($motDePasse, PASSWORD_DEFAULT);

    $query->bindValue(':nom', $nom);
    $query->bindValue(':prenom', $prenom);
    $query->bindValue(':email', $email);
    $query->bindValue(':motDePasse', $motDePasse);
    $query->bindValue(':fonction', $fonction);

    $success = $query->execute();

    if ($success) {
        $newUserId = $pdo->lastInsertId();

        if ($fonction === 'chef' && !empty($chantiers)) {
            $stmt = $pdo->prepare("INSERT INTO utilisateur_chantiers (utilisateur_id, chantier_id) VALUES (?, ?)");
            foreach ($chantiers as $chantier_id) {
                $stmt->execute([$newUserId, $chantier_id]);

                // Optionnel : tu peux aussi mettre à jour le responsable_id dans chantiers
                $pdo->prepare("UPDATE chantiers SET responsable_id = :user_id WHERE id = :chantier_id")
                    ->execute([':user_id' => $newUserId, ':chantier_id' => $chantier_id]);
            }
        }

        if ($fonction === 'depot') {
            $stmt = $pdo->prepare("UPDATE depots SET responsable_id = ? WHERE responsable_id IS NULL LIMIT 1");
            $stmt->execute([$newUserId]);
        }
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
