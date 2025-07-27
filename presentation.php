<?php
// Page publique : presentation.php
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bienvenue sur Simpliz - La gestion de chantier simplifiée</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .hero {
      background: url('/images/chantier-flou.jpg') center/cover no-repeat;
      color: white;
      text-shadow: 1px 1px 2px black;
      padding: 100px 20px;
      text-align: center;
    }
    .feature-icon {
      font-size: 2rem;
    }
  </style>
</head>
<body>

<!-- Header -->
<nav class="navbar navbar-expand-lg navbar-light bg-light border-bottom">
  <div class="container">
    <a class="navbar-brand fw-bold" href="#">Simpliz</a>
    <div class="d-flex">
      <a href="connexion.php" class="btn btn-outline-primary me-2">Connexion</a>
    </div>
  </div>
</nav>

<!-- Hero section -->
<section class="hero">
  <div class="container">
    <h1 class="display-4">Simpliz : la gestion de chantier simplifiée</h1>
    <p class="lead">Suivi de stock, congés, pointage… une seule interface.</p>
    <a href="connexion.php" class="btn btn-primary btn-lg mt-3">Commencer</a>
  </div>
</section>

<!-- Fonctionnalités -->
<section class="py-5">
  <div class="container">
    <h2 class="text-center mb-4">Fonctionnalités clés</h2>
    <div class="row g-4">
      <div class="col-md-3 text-center">
        <div class="feature-icon text-primary mb-2">🏗</div>
        <h5>Gestion du stock</h5>
        <p>Suivi en temps réel du matériel sur les dépôts et les chantiers.</p>
      </div>
      <div class="col-md-3 text-center">
        <div class="feature-icon text-success mb-2">🗓</div>
        <h5>Congés</h5>
        <p>Demandes et validations de congés simplifiées.</p>
      </div>
      <div class="col-md-3 text-center">
        <div class="feature-icon text-warning mb-2">⏱</div>
        <h5>Pointage</h5>
        <p>Consultez le pointage effectué par les supérieurs.</p>
      </div>
      <div class="col-md-3 text-center">
        <div class="feature-icon text-info mb-2">💡</div>
        <h5>Idées</h5>
        <p>Partagez des suggestions pour améliorer l’entreprise.</p>
      </div>
    </div>
  </div>
</section>

<!-- Avantages -->
<section class="bg-light py-5">
  <div class="container">
    <h2 class="text-center mb-4">Pourquoi Simpliz ?</h2>
    <div class="row">
      <div class="col-md-6">
        <ul class="list-group list-group-flush">
          <li class="list-group-item">✅ Interface claire et intuitive</li>
          <li class="list-group-item">✅ Accessible sur tous les appareils</li>
          <li class="list-group-item">✅ Hébergé en France, données sécurisées</li>
          <li class="list-group-item">✅ Facile à prendre en main pour tous</li>
        </ul>
      </div>
      <div class="col-md-6 text-center">
        <img src="/images/simpliz-preview.png" alt="Aperçu Simpliz" class="img-fluid rounded shadow-sm">
      </div>
    </div>
  </div>
</section>

<!-- Footer -->
<footer class="bg-dark text-white py-4">
  <div class="container d-flex justify-content-between flex-column flex-md-row text-center text-md-start">
    <div>
      <strong>© 2025 Simpliz</strong> – Tous droits réservés
    </div>
    <div>
      <a href="mailto:contact@simpliz.com" class="text-white text-decoration-none">📧 Contact</a>
    </div>
  </div>
</footer>

</body>
</html>
