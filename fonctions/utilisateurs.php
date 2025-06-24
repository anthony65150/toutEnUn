<?php
function ajouUtilisateur(PDO $pdo, string $nom, string $prenom, string $email, string $motDePasse, string $fonction): bool
{
    $query = $pdo->prepare("INSERT INTO utilisateurs (nom, prenom, email, motDePasse, fonction) VALUES (:nom, :prenom, :email, :motDePasse, :fonction)");

   $motDePasse = password_hash($motDePasse, PASSWORD_DEFAULT);

    $query->bindValue(':nom', $nom);
    $query->bindValue(':prenom', $prenom);
    $query->bindValue(':email', $email);
    $query->bindValue(':motDePasse', $motDePasse);
    $query->bindValue(':fonction', $fonction);



    return $query->execute();
}



function verifieUtilisateur($utilisateurs): array | bool
{
    $errors = [];

    if (isset($utilisateurs["nom"])) {
        if ($utilisateurs["nom"] === "") {
            $errors["nom"] = 'Le champ "Nom" est obligatoire ';
        } elseif ($utilisateurs["prenom"] === "") {
            $errors["prenom"] = 'Le champ "Prénom" est obligatoire ';
        } elseif ($utilisateurs["email"] === "") {
            $errors["email"] = 'Le champ "Email" est obligatoire ';
            if (!filter_var($utilisateurs["email"], FILTER_VALIDATE_EMAIL)) {
                $errors["email"] = "Le format d'email n'est pas respecté";
            }
        } elseif ($utilisateurs["motDePasse"] === "") {
            $errors["motDePasse"] = 'Le champ "Mot de passe" est obligatoire ';
        }elseif ($utilisateurs["fonction"] === "") {
         $errors["fonction"] = 'Le champ "Fonction" est obligatoire ';
        }
    }
    if (count($errors)) {
        return $errors;
    } else {
        return true;
    }
}

//fonction verification pour la connexion

function verifyUserLoginPassword(PDO $pdo, string $email, string $motDePasse): bool|array
{
    $query = $pdo->prepare("SELECT id, nom, prenom, email, photo, motDePasse, fonction FROM utilisateurs WHERE email = :email");
    $query->bindValue(":email", $email);
    $query->execute();
    $utilisateurs = $query->fetch(PDO::FETCH_ASSOC);

    if ($utilisateurs && password_verify($motDePasse, $utilisateurs["motDePasse"])) {
        // On peut supprimer le mot de passe avant de retourner les données
        unset($utilisateurs["motDePasse"]);
        return $utilisateurs;
    } else {
        return false;
    }
}


//fonction debug

/*function verifyUserLoginPassword1(PDO $pdo, string $email, string $motDePasse): bool|array
{
    $email = trim($email);
    $motDePasse = trim($motDePasse);

    $query = $pdo->prepare("SELECT id, nom, email, motDePasse FROM utilisateurs WHERE email = :email");
    $query->bindValue(":email", $email);
    $query->execute();

    $utilisateurs = $query->fetch(PDO::FETCH_ASSOC);

    echo "<pre>";
    echo "Email entré : $email\n";
    echo "Mot de passe entré : $motDePasse\n";
    echo "Résultat de la base :\n";
    var_dump($utilisateurs);
    echo "</pre>";

    if ($utilisateurs && password_verify($motDePasse, $utilisateurs["motDePasse"])) {
        echo "Mot de passe OK ✅<br>";
        unset($utilisateurs["motDePasse"]);
        return $utilisateurs;
    } else {
        echo "Mot de passe NON valide ❌<br>";
        return false;
    }
}*/
?>