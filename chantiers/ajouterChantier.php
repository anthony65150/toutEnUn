<?php
// /chantiers/ajouterChantier.php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';

if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur') {
  header('Location: /connexion.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: chantiers_admin.php'); exit;
}

/* ====== CSRF & Entreprise ====== */
if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
  $_SESSION['flash'] = "Erreur CSRF.";
  header("Location: chantiers_admin.php?success=error_csrf"); exit;
}
$entrepriseId = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);
if ($entrepriseId <= 0) {
  $_SESSION['flash'] = "Entreprise non définie.";
  header("Location: chantiers_admin.php?success=error"); exit;
}

/* ====== Inputs ====== */
$chantierId  = isset($_POST['chantier_id']) ? (int)$_POST['chantier_id'] : 0;
$nom         = trim($_POST['nom'] ?? '');
$adresse     = trim($_POST['adresse'] ?? '');
$description = trim($_POST['description'] ?? '');
$dateDebut   = ($_POST['date_debut'] ?? '') !== '' ? $_POST['date_debut'] : null;
$dateFin     = ($_POST['date_fin']   ?? '') !== '' ? $_POST['date_fin']   : null;

/* Dépôt rattaché (optionnel) */
$depotId = isset($_POST['depot_id']) ? (int)$_POST['depot_id'] : 0;
if ($depotId <= 0) { $depotId = null; }
else {
  $chkDepot = $pdo->prepare("SELECT 1 FROM depots WHERE id=:id AND entreprise_id=:eid");
  $chkDepot->execute([':id'=>$depotId, ':eid'=>$entrepriseId]);
  if (!$chkDepot->fetchColumn()) {
    $_SESSION['flash'] = "Dépôt invalide pour cette entreprise.";
    header("Location: chantiers_admin.php?success=error"); exit;
  }
}

/* Multi-chefs (chefs[]) ; fallback mono-chef (responsable_id) */
$chefIds = array_values(array_filter(array_map('intval', $_POST['chefs'] ?? [])));
if (!$chefIds && isset($_POST['responsable_id'])) {
  $rid = (int)$_POST['responsable_id'];
  if ($rid > 0) $chefIds = [$rid];
}

/* ====== Validations ====== */
if ($nom === '' || empty($chefIds)) {
  $_SESSION['flash'] = "Erreur : nom et au moins un chef sont requis.";
  header("Location: chantiers_admin.php?success=error"); exit;
}
if ($adresse === '') {
  $_SESSION['flash'] = "Erreur : l'adresse du chantier est requise.";
  header("Location: chantiers_admin.php?success=error"); exit;
}
if (mb_strlen($adresse) > 255) {
  $_SESSION['flash'] = "Adresse trop longue (255 caractères max).";
  header("Location: chantiers_admin.php?success=error"); exit;
}

/* Vérifier que tous les chefs appartiennent à l'entreprise */
$placeholders = implode(',', array_fill(0, count($chefIds), '?'));
$check = $pdo->prepare("SELECT COUNT(*) FROM utilisateurs WHERE id IN ($placeholders) AND entreprise_id = ?");
$check->execute([...$chefIds, $entrepriseId]);
if ((int)$check->fetchColumn() !== count($chefIds)) {
  $_SESSION['flash'] = "Un ou plusieurs chefs ne font pas partie de l'entreprise.";
  header("Location: chantiers_admin.php?success=error"); exit;
}

/* ====== Helpers Geocoding (serveur) ====== */
function http_get_json(string $url, array $headers = [], int $timeout = 8): ?array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => $timeout,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_HTTPHEADER     => $headers,
  ]);
  $res = curl_exec($ch);
  if ($res === false) { curl_close($ch); return null; }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code < 200 || $code >= 300) return null;
  $data = json_decode($res, true);
  return is_array($data) ? $data : null;
}

/**
 * Géocode via Google si clé dispo, sinon Nominatim (OSM).
 * Retourne [lat, lng] (floats) ou [null, null] si échec.
 */
function geocodeAdresse(string $adresse): array {
  $adresse = trim($adresse);
  if ($adresse === '') return [null, null];

  $googleKey = defined('GOOGLE_MAPS_KEY') ? constant('GOOGLE_MAPS_KEY') : (getenv('GOOGLE_MAPS_KEY') ?: null);
  if ($googleKey) {
    $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
      'address' => $adresse,
      'key'     => $googleKey,
      'language'=> 'fr',
      'region'  => 'fr',
    ]);
    $data = http_get_json($url);
    if ($data && ($data['status'] ?? '') === 'OK' && !empty($data['results'][0]['geometry']['location'])) {
      $loc = $data['results'][0]['geometry']['location'];
      return [ (float)$loc['lat'], (float)$loc['lng'] ];
    }
  }

  // Fallback Nominatim
  $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
    'q' => $adresse, 'format' => 'json', 'limit' => 1, 'addressdetails' => 0
  ]);
  $data = http_get_json($url, [
    'User-Agent: Simpliz/1.0 (+https://simpliz.local)',
    'Accept: application/json',
  ]);
  if ($data && !empty($data[0]['lat']) && !empty($data[0]['lon'])) {
    return [ (float)$data[0]['lat'], (float)$data[0]['lon'] ];
  }
  return [null, null];
}

/* ====== Responsable = premier chef ====== */
$responsableId = $chefIds[0];

try {
  $pdo->beginTransaction();

  if ($chantierId > 0) {
    /* ===== UPDATE (sécurisé par entreprise) ===== */
    $own = $pdo->prepare("SELECT adresse FROM chantiers WHERE id = :id AND entreprise_id = :eid");
    $own->execute([':id' => $chantierId, ':eid' => $entrepriseId]);
    $prev = $own->fetch(PDO::FETCH_ASSOC);
    if (!$prev) throw new RuntimeException("Chantier introuvable pour cette entreprise.");

    // Re-géocoder seulement si l’adresse a changé
    $shouldGeocode = trim($prev['adresse'] ?? '') !== $adresse;

    if ($shouldGeocode) {
      [$lat, $lng] = geocodeAdresse($adresse);
      $stmt = $pdo->prepare("
        UPDATE chantiers
           SET nom            = :nom,
               adresse        = :adresse,
               description    = :desc,
               date_debut     = :deb,
               date_fin       = :fin,
               responsable_id = :resp,
               depot_id       = :depot,
               adresse_lat    = :lat,
               adresse_lng    = :lng
         WHERE id = :id AND entreprise_id = :eid
      ");
      $stmt->execute([
        ':nom'=>$nom, ':adresse'=>$adresse, ':desc'=>$description,
        ':deb'=>$dateDebut, ':fin'=>$dateFin, ':resp'=>$responsableId,
        ':depot'=>$depotId, ':lat'=>$lat, ':lng'=>$lng,
        ':id'=>$chantierId, ':eid'=>$entrepriseId,
      ]);
    } else {
      $stmt = $pdo->prepare("
        UPDATE chantiers
           SET nom            = :nom,
               adresse        = :adresse,
               description    = :desc,
               date_debut     = :deb,
               date_fin       = :fin,
               responsable_id = :resp,
               depot_id       = :depot
         WHERE id = :id AND entreprise_id = :eid
      ");
      $stmt->execute([
        ':nom'=>$nom, ':adresse'=>$adresse, ':desc'=>$description,
        ':deb'=>$dateDebut, ':fin'=>$dateFin, ':resp'=>$responsableId,
        ':depot'=>$depotId,
        ':id'=>$chantierId, ':eid'=>$entrepriseId,
      ]);
    }

    // Reset liaisons et réinsertion
    $pdo->prepare("DELETE FROM utilisateur_chantiers WHERE chantier_id = :cid AND entreprise_id = :eid")
        ->execute([':cid' => $chantierId, ':eid' => $entrepriseId]);

    $ins = $pdo->prepare("INSERT INTO utilisateur_chantiers (utilisateur_id, chantier_id, entreprise_id)
                          VALUES (:uid, :cid, :eid)");
    foreach ($chefIds as $uid) {
      $ins->execute([':uid'=>$uid, ':cid'=>$chantierId, ':eid'=>$entrepriseId]);
    }

    $pdo->commit();
    $_SESSION['flash'] = "Chantier modifié avec succès.";
    $redirectId  = $chantierId;
    $successType = "update";

  } else {
    /* ===== INSERT ===== */
    [$lat, $lng] = geocodeAdresse($adresse);

    $stmt = $pdo->prepare("
      INSERT INTO chantiers
        (nom, adresse, description, date_debut, date_fin, responsable_id, entreprise_id,
         depot_id, adresse_lat, adresse_lng, created_at)
      VALUES
        (:nom, :adresse, :desc, :deb, :fin, :resp, :eid,
         :depot, :lat, :lng, NOW())
    ");
    $stmt->execute([
      ':nom'=>$nom, ':adresse'=>$adresse, ':desc'=>$description,
      ':deb'=>$dateDebut, ':fin'=>$dateFin, ':resp'=>$responsableId, ':eid'=>$entrepriseId,
      ':depot'=>$depotId, ':lat'=>$lat, ':lng'=>$lng,
    ]);
    $newChantierId = (int)$pdo->lastInsertId();

    $ins = $pdo->prepare("INSERT INTO utilisateur_chantiers (utilisateur_id, chantier_id, entreprise_id)
                          VALUES (:uid, :cid, :eid)");
    foreach ($chefIds as $uid) {
      $ins->execute([':uid'=>$uid, ':cid'=>$newChantierId, ':eid'=>$entrepriseId]);
    }

    $pdo->commit();
    $_SESSION['flash'] = "Chantier créé avec succès.";
    $redirectId  = $newChantierId;
    $successType = "create";
  }

  header("Location: chantiers_admin.php?success={$successType}&highlight={$redirectId}");
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  // error_log($e->getMessage());
  $_SESSION['flash'] = "Erreur serveur lors de l'enregistrement.";
  header("Location: chantiers_admin.php?success=error"); exit;
}
