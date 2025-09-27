<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json; charset=utf-8');

/* ========= Garde-fous ========= */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée'], JSON_UNESCAPED_UNICODE);
  exit;
}
if (!isset($_SESSION['utilisateurs']) || (($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur')) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Non autorisé'], JSON_UNESCAPED_UNICODE);
  exit;
}
$entrepriseId = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);
if ($entrepriseId <= 0) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Entreprise non sélectionnée'], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ========= CSRF ========= */
function assert_csrf(): void {
  $csrf = $_POST['csrf_token'] ?? '';
  if ($csrf === '' || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'CSRF invalide'], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

/* ========= Helpers HTTP / Géocodage ========= */
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
 * Essaie Google Geocoding si GOOGLE_MAPS_KEY est défini/env, sinon Nominatim.
 * Retourne [lat, lng] ou [null, null] si échec.
 */
function geocodeAdresse(string $rue, string $cp, string $ville): array {
  $query = trim($rue . ' ' . $cp . ' ' . $ville);
  if ($query === '') return [null, null];

  // 1) Google
  $googleKey = defined('GOOGLE_MAPS_KEY') ? constant('GOOGLE_MAPS_KEY') : (getenv('GOOGLE_MAPS_KEY') ?: null);
  if ($googleKey) {
    $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
      'address'  => $query,
      'key'      => $googleKey,
      'language' => 'fr',
      'region'   => 'fr',
    ]);
    $data = http_get_json($url);
    if ($data && ($data['status'] ?? '') === 'OK' && !empty($data['results'][0]['geometry']['location'])) {
      $loc = $data['results'][0]['geometry']['location'];
      return [(float)$loc['lat'], (float)$loc['lng']];
    }
  }

  // 2) Nominatim
  $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
    'q'              => $query,
    'format'         => 'json',
    'limit'          => 1,
    'addressdetails' => 0,
  ]);
  $data = http_get_json($url, [
    'User-Agent: Simpliz/1.0 (+https://simpliz.local)',
    'Accept: application/json',
  ]);
  if ($data && !empty($data[0]['lat']) && !empty($data[0]['lon'])) {
    return [(float)$data[0]['lat'], (float)$data[0]['lon']];
  }

  return [null, null];
}

/* ========= Router ========= */
$action = $_POST['action'] ?? '';

try {
  switch ($action) {

    /* ----- CREATE ----- */
    case 'create': {
      assert_csrf();

      $nom   = trim($_POST['nom'] ?? '');
      $addr  = trim($_POST['adresse'] ?? '');
      $cp    = trim($_POST['cp'] ?? '');
      $ville = trim($_POST['ville'] ?? '');
      $etat  = (($_POST['etat'] ?? 'en_cours') === 'fini') ? 'fini' : 'en_cours';

      if ($nom === '')                             throw new Exception("Le nom du chantier est requis.");
      if ($addr === '' || $cp === '' || $ville==='') throw new Exception("Adresse, CP et Ville sont requis.");

      [$lat, $lng] = geocodeAdresse($addr, $cp, $ville);

      $sql = "INSERT INTO chantiers
              (entreprise_id, nom, adresse, cp, ville, adresse_lat, adresse_lng, etat, created_at)
              VALUES (:eid, :nom, :adr, :cp, :ville, :lat, :lng, :etat, NOW())";
      $st = $pdo->prepare($sql);
      $st->execute([
        ':eid'   => $entrepriseId,
        ':nom'   => $nom,
        ':adr'   => $addr,
        ':cp'    => $cp,
        ':ville' => $ville,
        ':lat'   => $lat,
        ':lng'   => $lng,
        ':etat'  => $etat,
      ]);

      echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'etat' => $etat], JSON_UNESCAPED_UNICODE);
      exit;
    }

    /* ----- UPDATE ----- */
    case 'update': {
      assert_csrf();

      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new Exception("ID chantier manquant.");

      $old = $pdo->prepare("SELECT adresse, cp, ville FROM chantiers WHERE id=:id AND entreprise_id=:eid");
      $old->execute([':id' => $id, ':eid' => $entrepriseId]);
      $prev = $old->fetch(PDO::FETCH_ASSOC);
      if (!$prev) throw new Exception("Chantier introuvable.");

      $nom   = trim($_POST['nom'] ?? '');
      $addr  = trim($_POST['adresse'] ?? '');
      $cp    = trim($_POST['cp'] ?? '');
      $ville = trim($_POST['ville'] ?? '');
      $etatIn = $_POST['etat'] ?? null; // optionnel

      if ($nom === '')                             throw new Exception("Le nom du chantier est requis.");
      if ($addr === '' || $cp === '' || $ville==='') throw new Exception("Adresse, CP et Ville sont requis.");

      $shouldGeocode = (
        $addr  !== ($prev['adresse'] ?? '') ||
        $cp    !== ($prev['cp'] ?? '') ||
        $ville !== ($prev['ville'] ?? '')
      );

      $params = [
        ':nom'   => $nom,
        ':adr'   => $addr,
        ':cp'    => $cp,
        ':ville' => $ville,
        ':id'    => $id,
        ':eid'   => $entrepriseId,
      ];

      $sqlSet = "nom=:nom, adresse=:adr, cp=:cp, ville=:ville";
      if ($shouldGeocode) {
        [$lat, $lng] = geocodeAdresse($addr, $cp, $ville);
        $sqlSet .= ", adresse_lat=:lat, adresse_lng=:lng";
        $params[':lat'] = $lat;
        $params[':lng'] = $lng;
      }
      if ($etatIn !== null) {
        $etat = ($etatIn === 'fini') ? 'fini' : 'en_cours';
        $sqlSet .= ", etat=:etat";
        $params[':etat'] = $etat;
      }
      $sql = "UPDATE chantiers SET {$sqlSet}, updated_at=NOW()
              WHERE id=:id AND entreprise_id=:eid";

      $st = $pdo->prepare($sql);
      $st->execute($params);

      echo json_encode(['ok' => true, 'regeocode' => $shouldGeocode], JSON_UNESCAPED_UNICODE);
      exit;
    }

    /* ----- TOGGLE ETAT ----- */
    case 'toggle_etat': {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) throw new Exception("ID manquant.");

    // On lit l’état actuel
    $st = $pdo->prepare("SELECT etat FROM chantiers WHERE id=:id AND entreprise_id=:eid");
    $st->execute([':id'=>$id, ':eid'=>$entrepriseId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception("Chantier introuvable.");

    $newEtat = ($row['etat'] === 'fini') ? 'en_cours' : 'fini';

    $up = $pdo->prepare("
        UPDATE chantiers
           SET etat = :etat, updated_at = NOW()
         WHERE id = :id AND entreprise_id = :eid
    ");
    $up->execute([':etat'=>$newEtat, ':id'=>$id, ':eid'=>$entrepriseId]);

    echo json_encode(['ok'=>true, 'etat'=>$newEtat], JSON_UNESCAPED_UNICODE);
    exit;
}


    /* ----- DELETE ----- */
    case 'delete': {
      assert_csrf();

      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new Exception("ID chantier manquant.");

      $st = $pdo->prepare("DELETE FROM chantiers WHERE id=:id AND entreprise_id=:eid");
      $st->execute([':id' => $id, ':eid' => $entrepriseId]);

      echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
      exit;
    }

    default:
      throw new Exception("Action inconnue.");
  }
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
