<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../fonctions/pdo.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ================================
   Préférences de langue (simple)
================================ */
if (!isset($_SESSION['langue'])) {
    $_SESSION['langue'] = 'Français';
}
if (isset($_GET['langue'])) {
    $_SESSION['langue'] = htmlspecialchars((string)$_GET['langue'], ENT_QUOTES);
}
$langue = $_SESSION['langue'];

switch ($langue) {
    case 'Portugais': $htmlLang = 'pt'; break;
    case 'Arabe':     $htmlLang = 'ar'; break;
    case 'Espagnol':  $htmlLang = 'es'; break;
    case 'Roumain':   $htmlLang = 'ro'; break;
    default:          $htmlLang = 'fr';
}

$drapeaux = [
    'Français'  => '/images/france.png',
    'Portugais' => '/images/portugal.png',
    'Arabe'     => '/images/maroc.png',
    'Roumain'   => '/images/roumain.png',
    'Espagnol'  => '/images/espagne.png',
];
$drapeau = $drapeaux[$langue] ?? $drapeaux['Français'];

/* ================================
   Aides affichage
================================ */
$currentPage = basename($_SERVER['PHP_SELF']);
$isLogged    = isset($_SESSION['utilisateurs']);
$role        = $isLogged ? (string)($_SESSION['utilisateurs']['fonction'] ?? '') : '';
$isAdmin     = ($role === 'administrateur');
$isChef      = ($role === 'chef');
$isDepot     = ($role === 'depot'); // <-- AJOUT
$prenom      = $isLogged ? (string)($_SESSION['utilisateurs']['prenom'] ?? '') : '';

/* Photo profil sûre */
$photo = '/images/image-default.png';
if ($isLogged && !empty($_SESSION['utilisateurs']['photo'])) {
    $rel  = ltrim((string)$_SESSION['utilisateurs']['photo'], '/');
    $abs  = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . $rel;
    $ext  = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    if (is_file($abs) && in_array($ext, ['jpg','jpeg','png','webp'], true)) {
        $photo = '/' . $rel;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $htmlLang ?>">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) : 'Simpliz' ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="/images/Main.png" />

    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="/assets/css/override-bootstrap.css" />
    <link rel="stylesheet" href="/css/styles.css" />
    <link rel="stylesheet" href="/employes/css/planning.css" />
    <link rel="stylesheet" href="/pointage/css/pointage.css" />

    <style>
      /* ====== Cloche alertes (admin/chef/depot) ====== */
      #alertsBellIcon, #alertsBellIconMobile{
        display:inline-block;
        transform-origin:50% 0;
        will-change: transform;
      }
      @keyframes bell-ring{
        0%{transform:rotate(0)}10%{transform:rotate(18deg)}20%{transform:rotate(-16deg)}
        30%{transform:rotate(12deg)}40%{transform:rotate(-10deg)}50%{transform:rotate(8deg)}
        60%{transform:rotate(-6deg)}70%{transform:rotate(4deg)}80%{transform:rotate(-2deg)}
        90%{transform:rotate(1deg)}100%{transform:rotate(0)}
      }
      #alertsBell.ringing #alertsBellIcon,
      #alertsBellMobile.ringing #alertsBellIconMobile{
        animation: bell-ring 1.2s ease-in-out 0s 2;
      }
    </style>
</head>

<body class="d-flex flex-column min-vh-100 fond-gris"
      <?= isset($_POST['sous_categorie']) ? 'data-sous-categorie="'.htmlspecialchars((string)$_POST['sous_categorie'], ENT_QUOTES).'"' : '' ?>>

  <!-- NAVBAR -->
  <nav class="navbar navbar-expand-md navbar-light fond-gris border-bottom">
    <div class="container-fluid d-flex justify-content-between align-items-center">

      <!-- Logo -->
      <a class="navbar-brand d-flex align-items-center" href="/index.php">
        <img src="/images/simpliz-trans.png" alt="logo" width="100" height="100" />
      </a>

      <!-- Sélection langue (uniquement déconnecté) + dark mode -->
      <form action="" method="get" class="d-flex align-items-center gap-2 ms-auto">
        <?php if (!$isLogged): ?>
          <div class="dropdown">
            <!-- Desktop -->
            <button class="btn btn-outline-primary dropdown-toggle d-none d-md-flex align-items-center"
                    type="button" data-bs-toggle="dropdown" aria-expanded="false">
              <img src="<?= $drapeau ?>" alt="Langue" width="30" height="30" class="me-1">
              <?= htmlspecialchars($langue) ?>
            </button>
            <!-- Mobile -->
            <button class="btn btn-outline-primary dropdown-toggle d-md-none"
                    type="button" data-bs-toggle="dropdown" aria-expanded="false">
              <img src="<?= $drapeau ?>" alt="Langue" width="30" height="30">
            </button>
            <ul class="dropdown-menu">
              <li><button class="dropdown-item" type="submit" name="langue" value="Français">Français</button></li>
              <li><button class="dropdown-item" type="submit" name="langue" value="Portugais">Portugais</button></li>
              <li><button class="dropdown-item" type="submit" name="langue" value="Arabe">Arabe</button></li>
              <li><button class="dropdown-item" type="submit" name="langue" value="Espagnol">Espagnol</button></li>
              <li><button class="dropdown-item" type="submit" name="langue" value="Roumain">Roumain</button></li>
            </ul>
          </div>
        <?php endif; ?>

        <!-- (icône) Dark mode toggle : à brancher si besoin -->
        <button id="toggleDarkMode" type="button"
                style="font-size:1.5rem; border:none; background:none; cursor:pointer">
          🌙
        </button>
      </form>

      <!-- Bonjour centré (connecté) -->
      <?php if ($isLogged): ?>
        <div class="flex-grow-1 d-flex justify-content-center px-2">
          <div class="d-flex align-items-center flex-nowrap overflow-auto py-2 px-2 rounded" style="max-width:100%;">
            <a href="/mon-profil.php" class="me-2 flex-shrink-0">
              <img src="<?= htmlspecialchars($photo) ?>" alt="Photo de profil"
                   class="rounded-circle" style="width:40px; height:40px; object-fit:cover;">
            </a>
            <span class="fw-bold fs-6 text-nowrap bonjour-texte">
              Bonjour <?= htmlspecialchars($prenom) ?>
            </span>
          </div>
        </div>

        <!-- Zone droite (desktop ≥ md) -->
        <?php if ($isAdmin || $isChef || $isDepot): ?>
          <?php
            // Destination de la cloche selon le rôle
            $alertsHref =
              $isAdmin ? '/stock/alerts_admin.php'
              : ($isChef ? '/stock/alerts_chef.php' : '/stock/alerts_depot.php');
          ?>
          <div class="ms-auto d-none d-md-flex align-items-center gap-3 pe-3">
            <!-- Cloche alertes (ADMIN / CHEF / DÉPÔT) -->
            <a href="<?= $alertsHref ?>"
               class="position-relative text-decoration-none"
               id="alertsBell" aria-label="Voir les alertes">
              <i class="bi bi-bell-fill fs-4" id="alertsBellIcon"></i>
              <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none"
                    id="alertsBadge">0</span>
            </a>
            <a href="/deconnexion.php" class="nav-link text-danger">Déconnexion</a>
          </div>
        <?php else: ?>
          <div class="ms-auto d-none d-md-flex align-items-center pe-3">
            <a href="/deconnexion.php" class="nav-link text-danger">Déconnexion</a>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <!-- Mobile : cloche (admin/chef/dépôt) + burger -->
      <?php if ($currentPage !== 'index.php'): ?>
        <div class="d-md-none d-flex align-items-center gap-2">
          <?php if ($isAdmin || $isChef || $isDepot): ?>
            <?php
              $alertsHref =
                $isAdmin ? '/stock/alerts_admin.php'
                : ($isChef ? '/stock/alerts_chef.php' : '/stock/alerts_depot.php');
            ?>
            <a href="<?= $alertsHref ?>"
               class="position-relative text-decoration-none d-none"
               id="alertsBellMobile" aria-label="Voir les alertes">
              <i class="bi bi-bell-fill fs-5" id="alertsBellIconMobile"></i>
              <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                    id="alertsBadgeMobile">0</span>
            </a>
          <?php endif; ?>
          <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                  data-bs-target="#navbarBurgerMenu" aria-controls="navbarBurgerMenu"
                  aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
          </button>
        </div>
      <?php endif; ?>

     
    </div>
  </nav>

    <!-- Son de notification -->
  <audio id="alertSound" src="../sounds/BELLHand_Clochette 1 (ID 0292)_LS.mp3" preload="auto"></audio>

  <!-- Scripts cloche (unifiés) + son -->
  <script>
  (function(){
    const bellD   = document.getElementById('alertsBell');
    const badgeD  = document.getElementById('alertsBadge');
    const bellM   = document.getElementById('alertsBellMobile');
    const badgeM  = document.getElementById('alertsBadgeMobile');
    const soundEl = document.getElementById('alertSound');

    if (!bellD && !bellM) return; // pas de cloche à gérer

    // --- Déblocage audio mobile : un seul tap et l'audio sera autorisé ensuite
    function unlockAudioOnce(){
      if (!soundEl) return;
      const tryPlay = () => {
        soundEl.play().then(()=>{
          soundEl.pause();
          soundEl.currentTime = 0;
          window.removeEventListener('pointerdown', tryPlay, {capture:false});
          window.removeEventListener('click', tryPlay, {capture:false});
          window.removeEventListener('touchstart', tryPlay, {capture:false});
        }).catch(()=>{ /* ignore */ });
      };
      window.addEventListener('pointerdown', tryPlay, {once:true});
      window.addEventListener('click', tryPlay, {once:true});
      window.addEventListener('touchstart', tryPlay, {once:true});
    }
    unlockAudioOnce();

    // --- Mémoire locale pour éviter de re-sonner au refresh
    let prevCount = Number(sessionStorage.getItem('alerts_prev_count') || 0);
    let prevLastId = localStorage.getItem('alerts_last_id') || null;

    function play(el){
      if (!el) return;
      el.classList.remove('ringing');
      void el.offsetWidth; // reset anim
      el.classList.add('ringing');
    }
    function playDing(){
      if (!soundEl) return;
      // Évite de jouer si l’onglet est totalement caché (optionnel)
      if (document.hidden) return;
      try{
        soundEl.currentTime = 0;
        soundEl.play().catch(()=>{ /* bloqué par le navigateur ? débloqué au 1er tap */ });
      }catch(e){}
    }

    function updateVisual(count){
      const has = count > 0;

      if (badgeD && bellD){
        if (has){ badgeD.textContent = count; badgeD.classList.remove('d-none'); play(bellD); }
        else    { badgeD.classList.add('d-none'); bellD.classList.remove('ringing'); }
      }
      if (bellM && badgeM){
        if (has){ bellM.classList.remove('d-none'); badgeM.textContent = count; play(bellM); }
        else    { bellM.classList.add('d-none'); bellM.classList.remove('ringing'); }
      }
    }

    async function refresh(){
      try{
        const r = await fetch('/stock/api/alerts_unread_count.php', {credentials:'same-origin'});
        const j = await r.json();

        const count  = (j && j.ok) ? (j.count|0) : 0;
        const lastId = (j && j.last_id) ? String(j.last_id) : null;

        // Maj visuelle
        updateVisual(count);

        // Logique de son :
        // 1) si l'API expose last_id, on ne sonne que si un nouvel ID apparaît
        // 2) sinon fallback : on sonne si le count augmente
        let shouldDing = false;
        if (lastId){
          if (prevLastId && lastId !== prevLastId) shouldDing = true;
          if (!prevLastId && count > 0) shouldDing = true; // 1er chargement avec déjà des non-lus
          prevLastId = lastId;
          localStorage.setItem('alerts_last_id', lastId);
        }else{
          if (count > prevCount) shouldDing = true;
        }

        if (shouldDing) playDing();

        prevCount = count;
        sessionStorage.setItem('alerts_prev_count', String(prevCount));
      }catch(e){
        updateVisual(0);
      }
    }

    // initial + périodique
    refresh();
    setInterval(refresh, 60000);

    // petit rappel visuel toutes les 10s tant qu'il reste des non-lus (pas de son ici)
    setInterval(()=>{
      const anyVisible =
        (badgeD && !badgeD.classList.contains('d-none')) ||
        (bellM && !bellM.classList.contains('d-none'));
      if (anyVisible){ play(bellD || bellM); }
    }, 10000);
  })();
  </script>


  <!-- Google Maps (si utilisé ailleurs) -->
  <script>
    window.GMAPS_KEY = "<?= htmlspecialchars((string)GOOGLE_MAPS_API_KEY, ENT_QUOTES) ?>";
  </script>
  <script src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode((string)GOOGLE_MAPS_API_KEY) ?>&libraries=places" defer></script>

  <main class="flex-grow-1">
