<?php
require_once "fonctions/utilisateurs.php";
require_once "fonctions/pdo.php";
require_once "templates/header.php";

$error = null;


if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $utilisateurs = verifyUserLoginPassword($pdo, $_POST["email"], $_POST["motDePasse"]);
    if ($utilisateurs) {
        session_regenerate_id(true);
        $_SESSION["utilisateurs"] = [
            "id" => $utilisateurs["id"],
            "nom" => $utilisateurs["nom"],
            "prenom" => $utilisateurs["prenom"],
            "fonction" => $utilisateurs["fonction"]
        ];
        header("location: index.php");
    } else {
        $error = "Email ou mot de passe incorrect";
    }
}


?>
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

                                <form method="post">
                                    <div data-mdb-input-init class="form-outline mb-4">
                                        <label class="form-label" for="email">Email :</label>
                                        <input type="email" name="email" id="email" class="form-control"
                                            placeholder="Votre adresse email" />
                                    </div>

                                    <div data-mdb-input-init class="form-outline mb-4">
                                        <label class="form-label" for="motDePasse">Mot de passe :</label>
                                        <input type="password" id="motDePasse" name="motDePasse" class="form-control" placeholder="Votre mot de passe" />
                                    </div>

                                    <div class="text-center pt-1 mb-5 pb-1">
                                        <button data-mdb-button-init data-mdb-ripple-init class="btn btn-primary btn-block fa-lg mb-3" type="submit">Connexion</button>
                                    </div>

                                    <div class="d-flex align-items-center justify-content-center pb-4">
                                        <p class="mb-0 me-2">Vous n'avez pas de compte ?</p>
                                        <button type="button" data-mdb-button-init data-mdb-ripple-init class="btn btn-secondary">Inscription</button>
                                    </div>

                                </form>

                            </div>
                        </div>
                        <div class="col-lg-6 d-flex align-items-center gradient-custom">
                            <div class="text-white px-3 py-4 p-md-5 mx-md-4">
                                <h4 class="mb-4">Simpliz – La gestion d’entreprise, réinventée</h4>
                                <p class="small mb-0">
                                    Simpliz transforme la gestion quotidienne des employés et des employeurs. Une seule application pour une gestion simplifiée et une communication fluide.

                                    Pour les employés : Suivez vos congés, consultez la météo au travail, gérez votre pointage et le temps de trajet en un clin d'œil. Soumettez vos idées pour améliorer l’entreprise et demandez facilement des documents administratifs.

                                    Pour les employeurs : Prenez le contrôle total de votre entreprise en temps réel. Suivez l’état des stocks, gérez les livraisons, et contrôlez les pointages de vos employés, le tout sur une plateforme intuitive.

                                    Avec Simpliz, simplifiez la gestion et boostez l’efficacité de votre équipe. Plus qu’une application, c’est une véritable solution pour un environnement de travail plus harmonieux et productif.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php
require_once "templates/footer.php"
?>