<?php
declare(strict_types=1);

/**
 * Valide les données utilisateur (côté formulaire).
 * - $u['mode']: 'create' (défaut) ou 'update' -> mot de passe requis seulement en create
 * - Accepte soit $u['chantier_id'] (int), soit $u['chantiers'] (array legacy)
 */
function verifieUtilisateur(array $u): array|bool
{
    $errors = [];

    // Normalisation rôle
    $role = isset($u['fonction']) ? (string)$u['fonction'] : '';
    if (function_exists('mb_strtolower')) {
        $role = mb_strtolower(trim($role), 'UTF-8');
    } else {
        $role = strtolower(trim($role));
    }
    $role = strtr($role, [
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'à'=>'a','â'=>'a',
        'î'=>'i','ï'=>'i',
        'ô'=>'o','ö'=>'o',
        'ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c'
    ]);

    $allowed = ['administrateur','depot','chef','employe'];
    if (!in_array($role, $allowed, true)) {
        $errors['fonction'] = 'Le rôle sélectionné est invalide.';
    }

    // Champs basiques
    if (empty($u['nom']))    $errors['nom']    = 'Le champ "Nom" est obligatoire';
    if (empty($u['prenom'])) $errors['prenom'] = 'Le champ "Prénom" est obligatoire';

    if (empty($u['email'])) {
        $errors['email'] = 'Le champ "Email" est obligatoire';
    } elseif (!filter_var($u['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = "Le format d'email n'est pas respecté";
    }

    // Mot de passe : requis uniquement en création (ou si explicitement fourni en update)
    $mode = isset($u['mode']) ? (string)$u['mode'] : 'create';
    $pwd  = (string)($u['motDePasse'] ?? '');
    if ($mode === 'create') {
        if ($pwd === '' || strlen($pwd) < 6) {
            $errors['motDePasse'] = 'Mot de passe requis (min 6 caractères).';
        }
    } else { // update
        if ($pwd !== '' && strlen($pwd) < 6) {
            $errors['motDePasse'] = 'Mot de passe trop court (min 6 caractères).';
        }
    }

    // Chef : chantier obligatoire (accepte chantier_id *ou* chantiers[])
    if ($role === 'chef') {
        $hasOne = false;
        if (!empty($u['chantier_id'])) {
            $hasOne = true;
        } elseif (!empty($u['chantiers']) && is_array($u['chantiers'])) {
            $hasOne = count(array_filter($u['chantiers'])) > 0;
        }
        if (!$hasOne) {
            $errors['chantier_id'] = 'Merci de choisir un chantier pour un chef.';
        }
    }

    return empty($errors) ? true : $errors;
}

/**
 * Authentification : renvoie le user (sans motDePasse) si OK, sinon false.
 * Garde la signature existante (utilisée au login).
 */
function verifyUserLoginPassword(PDO $pdo, string $email, string $motDePasse): bool|array
{
    // NOTE: décommente "AND actif = 1" si tu as cette colonne
    $sql = "SELECT id, nom, prenom, email, photo, motDePasse, fonction, entreprise_id
            FROM utilisateurs
            WHERE email = :email /* AND actif = 1 */";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(":email", $email);
    $stmt->execute();

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($motDePasse, $user["motDePasse"])) {
        unset($user["motDePasse"]);
        return $user; // contient entreprise_id
    }
    return false;
}

/**
 * Helper d’insertion standardisé (optionnel, mais pratique)
 * - Pose entreprise_id depuis la session
 * - Gère agence_id (optionnel)
 * - Si rôle 'chef' + $chantier_id, insère la liaison dans utilisateur_chantiers
 *
 * @return int ID nouvel utilisateur
 */
function ajoutUtilisateur(
    PDO $pdo,
    string $nom,
    string $prenom,
    string $email,
    string $motDePasse,
    string $fonction,
    ?int $chantier_id = null,
    ?int $agence_id = null
): int {
    $entrepriseId = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);
    if ($entrepriseId <= 0) {
        throw new RuntimeException("Entreprise introuvable en session.");
    }

    $hash = password_hash($motDePasse, PASSWORD_DEFAULT);

    if ($fonction === 'chef' && $chantier_id) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO utilisateurs (nom, prenom, email, motDePasse, fonction, entreprise_id, agence_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$nom, $prenom, $email, $hash, $fonction, $entrepriseId, $agence_id]);

            $uid = (int)$pdo->lastInsertId();

            // Lier le chef au chantier
            $link = $pdo->prepare("INSERT INTO utilisateur_chantiers (utilisateur_id, chantier_id) VALUES (?, ?)");
            $link->execute([$uid, $chantier_id]);

            $pdo->commit();
            return $uid;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO utilisateurs (nom, prenom, email, motDePasse, fonction, entreprise_id, agence_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$nom, $prenom, $email, $hash, $fonction, $entrepriseId, $agence_id]);
        return (int)$pdo->lastInsertId();
    }
}
