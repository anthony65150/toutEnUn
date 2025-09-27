<?php
declare(strict_types=1);

/**
 * /depots/depots_api.php
 * API JSON pour créer / modifier / supprimer un dépôt
 * - Auth: administrateur
 * - Contexte: multi-entreprise (entreprise_id en session)
 * - Geocoding: Google (GOOGLE_MAPS_API_KEY) puis fallback Nominatim (OSM)
 */

require_once __DIR__ . '/../config/init.php';

header('Content-Type: application/json; charset=utf-8');

// ---------- Guards ----------
if (!isset($_SESSION['utilisateurs']) || (($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur')) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Non autorisé']);
  exit;
}
$entrepriseId = (int)($_SESSION['entreprise_id'] ?? 0);
if ($entrepriseId <= 0) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Entreprise non sélectionnée']);
  exit;
}

$action = (string)($_POST['action'] ?? '');

// ---------- Geocoding ----------
function geocodeAddress(string $address): ?array {
  if ($address === '') return null;

  // 1) Google si la clé est dispo
  $apiKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
  if ($apiKey !== '') {
    $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($address) . '&key=' . $apiKey;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => 10,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw && !$err) {
      $data = json_decode($raw, true);
      if (($data['status'] ?? '') === 'OK') {
        $loc = $data['results'][0]['geometry']['location'] ?? null;
        if ($loc && isset($loc['lat'], $loc['lng'])) {
          return ['lat' => (float)$loc['lat'], 'lng' => (float)$loc['lng']];
        }
      }
    }
  }

  // 2) Fallback OSM (Nominatim)
  $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . urlencode($address);
  $ch  = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => ['User-Agent: Simpliz/1.0 (contact: admin@example.com)'],
  ]);
  $raw = curl_exec($ch);
  $err = curl_error($ch);
  curl_close($ch);

  if ($raw && !$err) {
    $arr = json_decode($raw, true);
    if (is_array($arr) && !empty($arr[0]['lat']) && !empty($arr[0]['lon'])) {
      return ['lat' => (float)$arr[0]['lat'], 'lng' => (float)$arr[0]['lon']];
    }
  }

  return null;
}

// ---------- Helpers ----------
function checkResponsable(PDO $pdo, int $entrepriseId, ?int $responsableId): bool {
  if ($responsableId === null) return true;
  $st = $pdo->prepare("SELECT id FROM utilisateurs WHERE id = :uid AND entreprise_id = :eid");
  $st->execute([':uid' => $responsableId, ':eid' => $entrepriseId]);
  return (bool)$st->fetch();
}

// ---------- Actions ----------
try {
  if ($action === 'create') {
    $nom  = trim((string)($_POST['nom'] ?? ''));
    $addr = trim((string)($_POST['adresse'] ?? ''));
    $resp = ($_POST['responsable_id'] ?? '') !== '' ? (int)$_POST['responsable_id'] : null;

    if ($nom === '' || $addr === '') {
      echo json_encode(['ok' => false, 'error' => 'Champs manquants (nom/adresse)']);
      exit;
    }
    if (!checkResponsable($pdo, $entrepriseId, $resp)) {
      echo json_encode(['ok' => false, 'error' => 'Responsable hors entreprise']);
      exit;
    }

    $coords = geocodeAddress($addr);
    $lat = $coords['lat'] ?? null;
    $lng = $coords['lng'] ?? null;

    $st = $pdo->prepare("
      INSERT INTO depots (nom, adresse, adresse_lat, adresse_lng, responsable_id, entreprise_id)
      VALUES (:nom, :adresse, :lat, :lng, :resp, :eid)
    ");
    $st->execute([
      ':nom'     => $nom,
      ':adresse' => $addr,
      ':lat'     => $lat,
      ':lng'     => $lng,
      ':resp'    => $resp,
      ':eid'     => $entrepriseId,
    ]);

    echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    exit;
  }

  if ($action === 'update') {
    $id   = (int)($_POST['id'] ?? 0);
    $nom  = trim((string)($_POST['nom'] ?? ''));
    $addr = trim((string)($_POST['adresse'] ?? ''));
    $resp = ($_POST['responsable_id'] ?? '') !== '' ? (int)$_POST['responsable_id'] : null;

    if ($id <= 0 || $nom === '') {
      echo json_encode(['ok' => false, 'error' => 'Champs manquants (id/nom)']);
      exit;
    }
    if (!checkResponsable($pdo, $entrepriseId, $resp)) {
      echo json_encode(['ok' => false, 'error' => 'Responsable hors entreprise']);
      exit;
    }

    // Vérifie ownership + récupère ancienne adresse
    $own = $pdo->prepare("SELECT adresse FROM depots WHERE id = :id AND entreprise_id = :eid");
    $own->execute([':id' => $id, ':eid' => $entrepriseId]);
    $row = $own->fetch();
    if (!$row) {
      echo json_encode(['ok' => false, 'error' => 'Dépôt introuvable']);
      exit;
    }

    $needGeocode = ($addr !== '' && trim((string)$row['adresse']) !== $addr);

    if ($needGeocode) {
      $coords = geocodeAddress($addr);
      $lat = $coords['lat'] ?? null;
      $lng = $coords['lng'] ?? null;

      $st = $pdo->prepare("
        UPDATE depots
           SET nom = :nom,
               adresse = :adresse,
               adresse_lat = :lat,
               adresse_lng = :lng,
               responsable_id = :resp
         WHERE id = :id AND entreprise_id = :eid
      ");
      $st->execute([
        ':nom'     => $nom,
        ':adresse' => $addr,
        ':lat'     => $lat,
        ':lng'     => $lng,
        ':resp'    => $resp,
        ':id'      => $id,
        ':eid'     => $entrepriseId,
      ]);
    } else {
      // Pas de changement d’adresse → on préserve lat/lng
      $st = $pdo->prepare("
        UPDATE depots
           SET nom = :nom,
               adresse = :adresse,
               responsable_id = :resp
         WHERE id = :id AND entreprise_id = :eid
      ");
      $st->execute([
        ':nom'     => $nom,
        ':adresse' => $addr,
        ':resp'    => $resp,
        ':id'      => $id,
        ':eid'     => $entrepriseId,
      ]);
    }

    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
  }

  if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      echo json_encode(['ok' => false, 'error' => 'ID manquant']);
      exit;
    }
    $st = $pdo->prepare("DELETE FROM depots WHERE id = :id AND entreprise_id = :eid");
    $st->execute([':id' => $id, ':eid' => $entrepriseId]);
    echo json_encode(['ok' => true]);
    exit;
  }

  echo json_encode(['ok' => false, 'error' => 'Action inconnue']);
} catch (Throwable $e) {
  error_log('[depots_api] ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Erreur serveur']);
}
