<?php
require_once "./config/init.php";



// Si utilisateur déjà connecté, rediriger vers page d'accueil (index.php)
if (isset($_SESSION['utilisateurs'])) {
    header('Location: index.php');
    exit;
}

$error = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $utilisateurs = verifyUserLoginPassword($pdo, $_POST["email"], $_POST["motDePasse"]);
    if ($utilisateurs) {
        session_regenerate_id(true);
        $_SESSION["utilisateurs"] = [
            "id" => $utilisateurs["id"],
            "nom" => $utilisateurs["nom"],
            "prenom" => $utilisateurs["prenom"],
            "email" => $utilisateurs["email"],
            // Stocker la photo relative ou vide si pas de photo
            "photo" => !empty($utilisateurs["photo"]) ? $utilisateurs["photo"] : '',
            "fonction" => $utilisateurs["fonction"],
            "chantier_id" => (int)$utilisateurs["chantier_id"]
        ];
        header("Location: index.php");
        exit;
    } else {
        $error = "Email ou mot de passe incorrect";
    }
}

require_once "templates/header.php";
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Connexion - Simpliz</title>

    <!-- Empêche le cache navigateur -->
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="/assets/css/override-bootstrap.css" />
    <link rel="stylesheet" href="/css/styles.css" />
</head>

<body>
<section class="d-flex align-items-center justify-content-center">
    <div class="container mt-5 mb-5 pt-3">
        <div class="row d-flex justify-content-center align-items-center w-100">
            <div class="col-xl-12">
                <div class="card rounded-4 text-black">
                    <div class="row g-0" style="box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1)">
                        <div class="col-lg-6">
                            <div class="card-body p-md-5 mx-md-4">

                                <div class="text-center">
                                    <h3 class="mt-1 mb-5 pb-1 gradient-text">Bienvenue chez Simpliz</h3>
                                </div>

                                <?php if ($error) : ?>
                                    <div class="alert alert-danger"><?= $error ?></div>
                                <?php endif; ?>

                                <form method="post">
                                    <div class="form-outline mb-4">
                                        <label class="form-label" for="email">Email :</label>
                                        <input type="email" name="email" id="email" class="form-control" placeholder="Votre adresse email" />
                                    </div>

                                    <div class="form-outline mb-4">
                                        <label class="form-label" for="motDePasse">Mot de passe :</label>
                                        <input type="password" id="motDePasse" name="motDePasse" class="form-control" placeholder="Votre mot de passe" />
                                    </div>

                                    <div class="text-center pt-1 mb-5 pb-1">
                                        <button class="btn btn-primary btn-block fa-lg mb-3" type="submit">Connexion</button>
                                    </div>
                                </form>

                            </div>
                        </div>

                       <div class="col-lg-6 d-flex align-items-center gradient-custom">

                            <!-- Version mobile : texte court -->
                            <div class="mobile-text text-white px-3 py-4 p-md-5 mx-md-4 text-center d-lg-none">
                                <p><strong>Simpliz – La gestion d’entreprise, tout simplement facile</strong></p>
                                <ul class="list-unstyled mb-0">
                                    <li>- Suivez vos congés, pointages et trajets en un clic.</li>
                                    <li>- Demandez facilement vos documents administratifs.</li>
                                    <li>- Pour les employeurs, contrôlez stocks, livraisons et pointages en temps réel.</li>
                                </ul>
                                <p>Une solution simple pour un travail plus fluide et efficace.</p>
                            </div>

                            <!-- Version tablette & desktop : texte long -->
                            <div class="text-white px-3 py-4 p-md-5 mx-md-4 d-none d-lg-block">
                                <h4 class="mb-4">Simpliz – La gestion d’entreprise, tout simplement facile</h4>
                                <p class="small mb-0">
                                    <strong>- Simpliz</strong> transforme la gestion quotidienne des employés et des employeurs. Une seule application pour une gestion simplifiée et une communication fluide. <br><br>

                                    <strong>- Pour les employé</strong>s : Suivez vos congés, consultez la météo au travail, gérez votre pointage et le temps de trajet en un clin d'œil. Soumettez vos idées pour améliorer l’entreprise et demandez facilement des documents administratifs. <br><br>

                                    <strong>- Pour les employeurs</strong> : Prenez le contrôle total de votre entreprise en temps réel. Suivez l’état des stocks, gérez les livraisons, et contrôlez les pointages de vos employés, le tout sur une plateforme intuitive. <br><br>

                                    Avec <strong>Simpliz</strong>, simplifiez la gestion et boostez l’efficacité de votre équipe. Plus qu’une application, c’est une véritable solution pour un environnement de travail plus harmonieux et productif.
                                </p>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once "templates/footer.php"; ?>
</body>
</html>
