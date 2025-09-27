<?php
// /services/trajet_api.php
declare(strict_types=1);
require_once __DIR__ . '/../../config/init.php';

header('Content-Type: application/json; charset=utf-8');

/* ==== Sécurité ==== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'Méthode non autorisée']); exit;
}
if (!isset($_SESSION['utilisateurs']) || (($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur')) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'Non autorisé']); exit;
}
$entrepriseId = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);
if ($entrepriseId <= 0) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'Entreprise non définie']); exit;
}

/* ==== Helpers HTTP/Math ==== */
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

/** Distance Haversine (m) */
function haversine_m(float $lat1, float $lng1, float $lat2, float $lng2): float {
  $R = 6371000; // rayon Terre (m)
  $φ1 = deg2rad($lat1); $φ2 = deg2rad($lat2);
  $dφ = deg2rad($lat2 - $lat1);
  $dλ = deg2rad($lng2 - $lng1);
  $a  = sin($dφ/2)**2 + cos($φ1)*cos($φ2)*sin($dλ/2)**2;
  $c  = 2 * atan2(sqrt($a), sqrt(1-$a));
  return $R * $c;
}

/** Estimation durée (s) à partir de la distance (m) quand Google indispo. */
function estimate_duration_s(float $distance_m): int {
  // hypothèse 55 km/h moyenne + minimum 4 min
  $v_ms = 55 * 1000 / 3600;
  $t = (int)round($distance_m / $v_ms);
  return max($t, 240);
}

/* ==== Google key (optionnelle) ==== */
function google_key(): ?string {
  return defined('GOOGLE_MAPS_KEY') ? constant('GOOGLE_MAPS_KEY') : (getenv('GOOGLE_MAPS_KEY') ?: null);
}

/* ==== Récup coords dépôt ==== */
function get_depot_coords(PDO $pdo, int $entrepriseId, ?int $depotId=null): ?array {
  if ($depotId) {
    $st = $pdo->prepare("SELECT id, adresse_lat AS lat, adresse_lng AS lng
                         FROM depots WHERE id=:id AND entreprise_id=:eid");
    $st->execute([':id'=>$depotId, ':eid'=>$entrepriseId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['lat'] !== null && $row['lng'] !== null) return $row;
  }
  // fallback : premier dépôt de l’entreprise avec coords
  $st = $pdo->prepare("SELECT id, adresse_lat AS lat, adresse_lng AS lng
                       FROM depots WHERE entreprise_id=:eid
                       AND adresse_lat IS NOT NULL AND adresse_lng IS NOT NULL
                       ORDER BY id ASC LIMIT 1");
  $st->execute([':eid'=>$entrepriseId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

/* ==== Récup coords chantier ==== */
function get_chantier_coords(PDO $pdo, int $entrepriseId, int $chantierId): ?array {
  $st = $pdo->prepare("SELECT id, adresse_lat AS lat, adresse_lng AS lng
                       FROM chantiers WHERE id=:id AND entreprise_id=:eid");
  $st->execute([':id'=>$chantierId, ':eid'=>$entrepriseId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return ($row && $row['lat'] !== null && $row['lng'] !== null) ? $row : null;
}
function osrm_route(string $orig, string $dest): ?array {
  // $orig/$dest "lat,lng"
  // OSRM attend "lng,lat"
  [$olat,$olng] = array_map('floatval', explode(',', $orig));
  [$dlat,$dlng] = array_map('floatval', explode(',', $dest));
  $coords = $olng . ',' . $olat . ';' . $dlng . ',' . $dlat;

  $url = "https://router.project-osrm.org/route/v1/driving/$coords?"
       . http_build_query([
           'overview'     => 'false',
           'alternatives' => 'true', // on regarde la plus courte
           'steps'        => 'false',
         ]);
  $data = http_get_json($url, ['User-Agent: Simpliz/1.0']);
  if (!$data || ($data['code'] ?? '') !== 'Ok' || empty($data['routes'])) return null;

  // Choisir **la plus courte en distance**
  $best = null;
  foreach ($data['routes'] as $r) {
    if (!$best || $r['distance'] < $best['distance']) $best = $r;
  }
  if (!$best) return null;

  return [
    'distance_m' => (int) round($best['distance']), // mètres
    'duration_s' => (int) round($best['duration']), // secondes
    'source'     => 'osrm'
  ];
}


/* ==== Calcul principal ==== */
function compute_distance_and_duration(PDO $pdo, int $entrepriseId, int $chantierId, ?int $depotId=null): array {
  $chantier = get_chantier_coords($pdo, $entrepriseId, $chantierId);
  if (!$chantier) throw new RuntimeException("Coordonnées chantier manquantes.");
  $depot = get_depot_coords($pdo, $entrepriseId, $depotId);
  if (!$depot) throw new RuntimeException("Coordonnées dépôt manquantes.");

    $o = "{$depot['lat']},{$depot['lng']}";
  $d = "{$chantier['lat']},{$chantier['lng']}";

  $distance_m = null; $duration_s = null; $source = null;

  // 1) Google Directions API (trajet voiture, plus court par distance parmi alternatives)
  $key = google_key();
  if ($key) {
    $url = 'https://maps.googleapis.com/maps/api/directions/json?' . http_build_query([
      'origin'        => $o,
      'destination'   => $d,
      'mode'          => 'driving',
      'language'      => 'fr',
      'units'         => 'metric',
      'departure_time'=> 'now',         // pour durées trafic dans legs[].duration_in_traffic
      'alternatives'  => 'true',        // on choisit la PLUS COURTE DISTANCE
      'region'        => 'fr',
      'key'           => $key,
    ]);
    $dir = http_get_json($url);
    if ($dir && ($dir['status'] ?? '') === 'OK' && !empty($dir['routes'])) {
      // choisir la route la plus courte (distance) parmi les alternatives
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
        $source = 'google';
      }
    }
  }

  // 2) Fallback **OSRM driving** (jamais à vol d’oiseau)
  if ($distance_m === null || $duration_s === null) {
    $osrm = osrm_route($o, $d);
    if ($osrm) {
      $distance_m = $osrm['distance_m'];
      $duration_s = $osrm['duration_s'];
      $source     = $osrm['source'];
    }
  }

  if ($distance_m === null || $duration_s === null) {
    throw new RuntimeException("Impossible de calculer un itinéraire routier.");
  }

  // Sauvegarde
  $st = $pdo->prepare("UPDATE chantiers
                       SET trajet_distance_m=:dm, trajet_duree_s=:ds, trajet_last_calc=NOW()
                       WHERE id=:cid AND entreprise_id=:eid");
  $st->execute([':dm'=>$distance_m, ':ds'=>$duration_s, ':cid'=>$chantierId, ':eid'=>$entrepriseId]);

  return [
    'ok' => true,
    'source' => $source,            // 'google' ou 'osrm'
    'distance_m' => (int)$distance_m,
    'duration_s' => (int)$duration_s,
    'chantier_id' => $chantierId,
    'depot_id' => $depot['id'] ?? null,
  ];

}

/* ==== Router ==== */
$action   = $_POST['action'] ?? '';
$chantier = (int)($_POST['chantier_id'] ?? 0);
$depot    = isset($_POST['depot_id']) ? (int)$_POST['depot_id'] : null;
$force    = (int)($_POST['force'] ?? 0); // 1 = recalc même si récent

try {
  switch ($action) {
    case 'compute': {
      if ($chantier <= 0) throw new InvalidArgumentException("chantier_id requis.");

      // si pas force, on évite le recalcul trop fréquent (24h)
      if (!$force) {
        $st = $pdo->prepare("SELECT trajet_last_calc FROM chantiers WHERE id=:id AND entreprise_id=:eid");
        $st->execute([':id'=>$chantier, ':eid'=>$entrepriseId]);
        $last = $st->fetchColumn();
        if ($last && (time() - strtotime((string)$last)) < 24*3600) {
          echo json_encode(['ok'=>true, 'skipped'=>true, 'reason'=>'recent']); exit;
        }
      }

      $out = compute_distance_and_duration($pdo, $entrepriseId, $chantier, $depot);
      echo json_encode($out); break;
    }

    case 'compute_all': {
      // Recalcule pour tous les chantiers avec coords valides.
      $st = $pdo->prepare("SELECT id FROM chantiers
                           WHERE entreprise_id=:eid
                           AND adresse_lat IS NOT NULL AND adresse_lng IS NOT NULL");
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
          compute_distance_and_duration($pdo, $entrepriseId, (int)$cid, $depot);
          $done++;
          // throttle léger si Nominatim, pour être sympa (et éviter 429)
          if (!google_key()) usleep(250000); // 0.25s
        } catch (Throwable $e) {
          $errors[] = ['id'=>$cid, 'error'=>$e->getMessage()];
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
