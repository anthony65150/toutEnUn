<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';

if (!isset($_SESSION['utilisateurs']) || (($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur')) {
    header("Location: ../connexion.php");
    exit;
}

$entrepriseId = (int)($_SESSION['entreprise_id'] ?? 0);
if ($entrepriseId <= 0) {
    http_response_code(403);
    exit('Entreprise non sélectionnée');
}

/* ====== Inputs ====== */
$depotId        = (int)($_POST['id'] ?? 0);
$nom            = trim((string)($_POST['nom'] ?? ''));
$adresse        = trim((string)($_POST['adresse'] ?? ''));   // <— IMPORTANT
$responsable_id = ($_POST['responsable_id'] ?? '') !== '' ? (int)$_POST['responsable_id'] : null;

if ($nom === '') {
    header("Location: ./depots_admin.php?error=nom_obligatoire");
    exit;
}

/* ====== Responsable dans la même entreprise (si fourni) ====== */
if ($responsable_id !== null) {
    $chkResp = $pdo->prepare("SELECT id FROM utilisateurs WHERE id = :uid AND entreprise_id = :eid");
    $chkResp->execute([':uid' => $responsable_id, ':eid' => $entrepriseId]);
    if (!$chkResp->fetch()) {
        header("Location: ./depots_admin.php?error=responsable_hors_entreprise");
        exit;
    }
}

/* ====== Geocoding helper ====== */
function geocodeAddress(string $address): ?array {
    if ($address === '') return null;

    // 1) Google si la clé existe
    $apiKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
    if ($apiKey) {
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($address) . '&key=' . $apiKey;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10]);
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
            } else {
                error_log('[GEOCODE][Google] status=' . ($data['status'] ?? '??') . ' addr=' . $address);
            }
        } else {
            error_log('[GEOCODE][Google] curl=' . $err);
        }
    }

    // 2) Fallback OSM
    $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . urlencode($address);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>10,
        CURLOPT_HTTPHEADER=>['User-Agent: Simpliz/1.0 (contact: admin@example.com)']
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw && !$err) {
        $arr = json_decode($raw, true);
        if (is_array($arr) && !empty($arr[0]['lat']) && !empty($arr[0]['lon'])) {
            return ['lat' => (float)$arr[0]['lat'], 'lng' => (float)$arr[0]['lon']];
        }
    } else {
        error_log('[GEOCODE][OSM] curl=' . $err);
    }

    return null;
}

try {
    // On garantit le type des colonnes si besoin (no-op si déjà bon)
    $pdo->exec("ALTER TABLE depots 
        MODIFY adresse_lat DECIMAL(10,7) NULL,
        MODIFY adresse_lng DECIMAL(10,7) NULL
    ");

    if ($depotId > 0) {
        /* ===== UPDATE ===== */
        $own = $pdo->prepare("SELECT id, adresse FROM depots WHERE id = :id AND entreprise_id = :eid");
        $own->execute([':id' => $depotId, ':eid' => $entrepriseId]);
        $row = $own->fetch();
        if (!$row) {
            header("Location: ./depots_admin.php?error=depot_introuvable");
            exit;
        }

        $needGeocode = false;
        $lat = null; $lng = null;

        // Géocode seulement si l'adresse a changé
        $oldAdresse = trim((string)$row['adresse'] ?? '');
        if ($adresse !== '' && $adresse !== $oldAdresse) {
            $coords = geocodeAddress($adresse);
            if ($coords) { $lat = $coords['lat']; $lng = $coords['lng']; }
            $needGeocode = true;
        }

        if ($needGeocode) {
            $stmt = $pdo->prepare("
                UPDATE depots
                   SET nom = :nom,
                       adresse = :adresse,
                       adresse_lat = :lat,
                       adresse_lng = :lng,
                       responsable_id = :resp
                 WHERE id = :id AND entreprise_id = :eid
            ");
            $stmt->execute([
                ':nom'      => $nom,
                ':adresse'  => $adresse,
                ':lat'      => $lat,
                ':lng'      => $lng,
                ':resp'     => $responsable_id,
                ':id'       => $depotId,
                ':eid'      => $entrepriseId,
            ]);
        } else {
            // Pas de changement d'adresse → on n’écrase pas lat/lng
            $stmt = $pdo->prepare("
                UPDATE depots
                   SET nom = :nom,
                       adresse = :adresse,
                       responsable_id = :resp
                 WHERE id = :id AND entreprise_id = :eid
            ");
            $stmt->execute([
                ':nom'      => $nom,
                ':adresse'  => $adresse, // si vide on laisse vide; adapte selon ton UX
                ':resp'     => $responsable_id,
                ':id'       => $depotId,
                ':eid'      => $entrepriseId,
            ]);
        }

        header("Location: ./depots_admin.php?success=update&highlight={$depotId}");
        exit;

    } else {
        /* ===== CREATE ===== */
        $lat = null; $lng = null;
        if ($adresse !== '') {
            $coords = geocodeAddress($adresse);
            if ($coords) { $lat = $coords['lat']; $lng = $coords['lng']; }
        }

        $stmt = $pdo->prepare("
            INSERT INTO depots (nom, adresse, adresse_lat, adresse_lng, responsable_id, entreprise_id)
            VALUES (:nom, :adresse, :lat, :lng, :resp, :eid)
        ");
        $stmt->execute([
            ':nom'     => $nom,
            ':adresse' => $adresse,
            ':lat'     => $lat,
            ':lng'     => $lng,
            ':resp'    => $responsable_id,
            ':eid'     => $entrepriseId,
        ]);

        $newId = (int)$pdo->lastInsertId();
        header("Location: ./depots_admin.php?success=create&highlight={$newId}");
        exit;
    }

} catch (PDOException $e) {
    if (($e->errorInfo[1] ?? 0) == 1062) {
        header("Location: ./depots_admin.php?error=nom_deja_utilise");
        exit;
    }
    error_log('[DEPOTS_SAVE] ' . $e->getMessage());
    header("Location: ./depots_admin.php?error=server");
    exit;
}
