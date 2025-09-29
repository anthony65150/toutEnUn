<?php
// /chantiers/services/trajet_api.php
declare(strict_types=1);
require_once __DIR__ . '/../../config/init.php';

header('Content-Type: application/json; charset=utf-8');

/* =========================
   Sécurité session
   ========================= */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'Méthode non autorisée']); exit;
}
if (!isset($_SESSION['utilisateurs'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'Non authentifié']); exit;
}
$user         = $_SESSION['utilisateurs'];
$role         = strtolower((string)($user['fonction'] ?? ''));
$entrepriseId = (int)($user['entreprise_id'] ?? 0);
if ($entrepriseId <= 0) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'Entreprise non définie']); exit;
}

/* =========================
   Utilitaires HTTP/Math
   ========================= */
function http_get_json(string $url, array $headers = [], int $timeout=8): ?array {
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

/** Distance Haversine (m) – utile en dernier recours */
function haversine_m(float $lat1, float $lng1, float $lat2, float $lng2): float {
  $R = 6371000; // m
  $φ1 = deg2rad($lat1); $φ2 = deg2rad($lat2);
  $dφ = deg2rad($lat2 - $lat1);
  $dλ = deg2rad($lng2 - $lng1);
  $a  = sin($dφ/2)**2 + cos($φ1)*cos($φ2)*sin($dλ/2)**2;
  $c  = 2 * atan2(sqrt($a), sqrt(1-$a));
  return $R * $c;
}

/** Estimation de durée (s) si tout échoue */
function estimate_duration_s(float $distance_m): int {
  $v_ms = 55 * 1000 / 3600; // 55 km/h
  $t = (int)round($distance_m / $v_ms);
  return max($t, 240); // min 4 min
}

/* =========================
   Google key (optionnelle)
   ========================= */
function google_key(): ?string {
  return defined('GOOGLE_MAPS_KEY') ? constant('GOOGLE_MAPS_KEY') : (getenv('GOOGLE_MAPS_KEY') ?: null);
}

/* =========================
   OSRM (fallback routier)
   ========================= */
function osrm_route(string $orig, string $dest): ?array {
  // $orig/$dest "lat,lng" -> OSRM attend "lng,lat"
  [$olat,$olng] = array_map('floatval', explode(',', $orig));
  [$dlat,$dlng] = array_map('floatval', explode(',', $dest));
  $coords = $olng . ',' . $olat . ';' . $dlng . ',' . $dlat;

  $url = "https://router.project-osrm.org/route/v1/driving/$coords?" . http_build_query([
    'overview'     => 'false',
    'alternatives' => 'true',
    'steps'        => 'false',
  ]);
  $data = http_get_json($url, ['User-Agent: Simpliz/1.0']);
  if (!$data || ($data['code'] ?? '') !== 'Ok' || empty($data['routes'])) return null;

  // Choisir la plus courte en distance
  $best = null;
  foreach ($data['routes'] as $r) {
    if (!$best || $r['distance'] < $best['distance']) $best = $r;
  }
  if (!$best) return null;

  return [
    'distance_m' => (int) round($best['distance']),
    'duration_s' => (int) round($best['duration']),
    'source'     => 'osrm'
  ];
}

/* =========================
   Lecture chantier + dépôt
   ========================= */
function get_chantier_join_depot(PDO $pdo, int $entrepriseId, int $chantierId): ?array {
  $st = $pdo->prepare("
    SELECT
      c.id                 AS chantier_id,
      c.adresse_lat        AS c_lat,
      c.adresse_lng        AS c_lng,
      c.trajet_last_calc   AS last_calc,
      c.depot_id           AS depot_id,
      d.nom                AS depot_nom,
      d.adresse_lat        AS d_lat,
      d.adresse_lng        AS d_lng
    FROM chantiers c
    JOIN depots d ON d.id = c.depot_id
    WHERE c.id = :cid AND c.entreprise_id = :eid
  ");
  $st->execute([':cid'=>$chantierId, ':eid'=>$entrepriseId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) return null;
  if ($row['c_lat'] === null || $row['c_lng'] === null) return null;
  if ($row['d_lat'] === null || $row['d_lng'] === null) return null;
  return $row;
}

/* =========================
   Calcul principal (verrouillé sur dépôt du chantier)
   ========================= */
function compute_distance_and_duration(PDO $pdo, int $entrepriseId, int $chantierId): array {
  $row = get_chantier_join_depot($pdo, $entrepriseId, $chantierId);
  if (!$row) throw new RuntimeException("Coordonnées chantier/dépôt manquantes ou chantier introuvable.");

  $o = "{$row['d_lat']},{$row['d_lng']}"; // origine = dépôt du chantier (BDD)
  $d = "{$row['c_lat']},{$row['c_lng']}";

  $distance_m = null; $duration_s = null; $source = null;

  // 1) Google Directions (si clé dispo)
  $key = google_key();
  if ($key) {
    $url = 'https://maps.googleapis.com/maps/api/directions/json?' . http_build_query([
      'origin'        => $o,
      'destination'   => $d,
      'mode'          => 'driving',
      'language'      => 'fr',
      'units'         => 'metric',
      'departure_time'=> 'now',
      'alternatives'  => 'true',
      'region'        => 'fr',
      'key'           => $key,
    ]);
    $dir = http_get_json($url);
    if ($dir && ($dir['status'] ?? '') === 'OK' && !empty($dir['routes'])) {
      $bestRoute = null; $bestDist = null; $bestDur = null;
      foreach ($dir['routes'] as $route) {
        $rDist = 0; $rDur = 0; $rDurTraffic = 0; $hasTraffic = false;
        foreach ($route['legs'] ?? [] as $leg) {
          $rDist += (int)($leg['distance']['value'] ?? 0);
          $rDur  += (int)($leg['duration']['value'] ?? 0);
          if (isset($leg['duration_in_traffic']['value'])) {
            $rDurTraffic += (int)$leg['duration_in_traffic']['value'];
            $hasTraffic = true;
          }
        }
        $rDurFinal = $hasTraffic ? $rDurTraffic : $rDur;
        if ($bestRoute === null || $rDist < $bestDist) {
          $bestRoute = $route; $bestDist = $rDist; $bestDur = $rDurFinal;
        }
      }
      if ($bestRoute !== null && $bestDist > 0 && $bestDur > 0) {
        $distance_m = (int)$bestDist;
        $duration_s = (int)$bestDur;
        $source     = 'google';
      }
    }
  }

  // 2) Fallback OSRM (toujours routier)
  if ($distance_m === null || $duration_s === null) {
    $osrm = osrm_route($o, $d);
    if ($osrm) {
      $distance_m = $osrm['distance_m'];
      $duration_s = $osrm['duration_s'];
      $source     = $osrm['source'];
    }
  }

  // 3) Dernier ressort : Haversine + estimation (évite 0)
  if ($distance_m === null || $duration_s === null) {
    $distance_m = (int)round(haversine_m((float)$row['d_lat'], (float)$row['d_lng'], (float)$row['c_lat'], (float)$row['c_lng']));
    $duration_s = estimate_duration_s($distance_m);
    $source     = 'estimate';
  }

  // Sauvegarde sur le chantier
  $st = $pdo->prepare("
    UPDATE chantiers
       SET trajet_distance_m = :dm,
           trajet_duree_s    = :ds,
           trajet_last_calc  = NOW()
     WHERE id = :cid AND entreprise_id = :eid
  ");
  $st->execute([
    ':dm'  => $distance_m,
    ':ds'  => $duration_s,
    ':cid' => $chantierId,
    ':eid' => $entrepriseId
  ]);

  return [
    'ok'          => true,
    'source'      => $source,            // 'google' | 'osrm' | 'estimate'
    'distance_m'  => (int)$distance_m,
    'duration_s'  => (int)$duration_s,
    'chantier_id' => $chantierId,
    'depot_id'    => (int)$row['depot_id'],
    'depot_nom'   => (string)$row['depot_nom'],
  ];
}

/* =========================
   Lecture payload (form ou JSON)
   ========================= */
$ct = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($ct, 'application/json') !== false) {
  $raw = file_get_contents('php://input');
  $POST = json_decode($raw, true) ?: [];
} else {
  $POST = $_POST;
}

$action   = (string)($POST['action'] ?? 'compute');
$chantier = (int)($POST['chantier_id'] ?? 0);
$force    = (int)($POST['force'] ?? 0);

/* =========================
   Router
   ========================= */
try {
  switch ($action) {

    case 'compute': {
      // Autorisé à tout utilisateur connecté de l’entreprise
      if ($chantier <= 0) throw new InvalidArgumentException("chantier_id requis.");

      // anti-recalcul si récent (24h) sauf force
      if (!$force) {
        $st = $pdo->prepare("SELECT trajet_last_calc FROM chantiers WHERE id=:id AND entreprise_id=:eid");
        $st->execute([':id'=>$chantier, ':eid'=>$entrepriseId]);
        $last = $st->fetchColumn();
        if ($last && (time() - strtotime((string)$last)) < 24*3600) {
          echo json_encode(['ok'=>true, 'skipped'=>true, 'reason'=>'recent']); exit;
        }
      }

      $out = compute_distance_and_duration($pdo, $entrepriseId, $chantier); // ⛔️ pas de depot_id ici
      echo json_encode($out);
      break;
    }

    case 'compute_all': {
      // Réservé admin
      if ($role !== 'administrateur') {
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'Non autorisé']); exit;
      }

      $st = $pdo->prepare("
        SELECT c.id
          FROM chantiers c
          JOIN depots d ON d.id = c.depot_id
         WHERE c.entreprise_id = :eid
           AND c.adresse_lat IS NOT NULL AND c.adresse_lng IS NOT NULL
           AND d.adresse_lat IS NOT NULL AND d.adresse_lng IS NOT NULL
      ");
      $st->execute([':eid'=>$entrepriseId]);
      $ids = $st->fetchAll(PDO::FETCH_COLUMN, 0);

      $done = 0; $errors = [];
      foreach ($ids as $cid) {
        try {
          if (!$force) {
            $cst = $pdo->prepare("SELECT trajet_last_calc FROM chantiers WHERE id=:id AND entreprise_id=:eid");
            $cst->execute([':id'=>$cid, ':eid'=>$entrepriseId]);
            $last = $cst->fetchColumn();
            if ($last && (time() - strtotime((string)$last)) < 24*3600) continue;
          }
          compute_distance_and_duration($pdo, $entrepriseId, (int)$cid);
          $done++;
          if (!google_key()) usleep(250000); // 0.25s si pas Google
        } catch (Throwable $e) {
          $errors[] = ['id'=>(int)$cid, 'error'=>$e->getMessage()];
        }
      }
      echo json_encode(['ok'=>true, 'updated'=>$done, 'errors'=>$errors]);
      break;
    }

    default:
      throw new InvalidArgumentException("Action inconnue.");
  }

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
