<?php
// /agences/api.php
declare(strict_types=1);
require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['utilisateurs'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'msg'=>'Non authentifié']); exit;
}

$pdo          = $pdo ?? null; // init.php
$entrepriseId = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);
$method       = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action       = $_GET['action'] ?? $_POST['action'] ?? '';

function bad_request($msg='Requête invalide'){ http_response_code(400); echo json_encode(['ok'=>false,'msg'=>$msg]); exit; }
function ensure_csrf() {
  if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(400); echo json_encode(['ok'=>false,'msg'=>'Token CSRF invalide']); exit;
  }
}
function norm(string $s): string {
  $s = trim(preg_replace('/\s+/u',' ', $s)); // espaces multiples -> 1
  return $s;
}

/* ====== LIST ====== */
if ($action === 'list' && $method === 'GET') {
  $q = norm((string)($_GET['q'] ?? ''));
  if ($q !== '') {
    // recherche insensible à la casse
    $stmt = $pdo->prepare("
      SELECT id, nom, adresse, actif
      FROM agences
      WHERE entreprise_id=? AND actif=1 AND LOWER(nom) LIKE LOWER(?)
      ORDER BY nom
    ");
    $stmt->execute([$entrepriseId, "%$q%"]);
  } else {
    $stmt = $pdo->prepare("
      SELECT id, nom, adresse, actif
      FROM agences
      WHERE entreprise_id=? AND actif=1
      ORDER BY nom
    ");
    $stmt->execute([$entrepriseId]);
  }
  echo json_encode(['ok'=>true,'items'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]); exit;
}

/* ====== SHOW ====== */
if ($action === 'show' && $method === 'GET') {
  $id = (int)($_GET['id'] ?? 0);
  if (!$id) bad_request();
  $stmt = $pdo->prepare("SELECT id, nom, adresse, actif FROM agences WHERE id=? AND entreprise_id=?");
  $stmt->execute([$id, $entrepriseId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) { http_response_code(404); echo json_encode(['ok'=>false,'msg'=>'Introuvable']); exit; }
  echo json_encode(['ok'=>true,'item'=>$row]); exit;
}

/* ====== POST actions avec CSRF ====== */
if (in_array($action, ['create','update','delete'], true)) ensure_csrf();

// ------- CREATE -------
if ($action === 'create' && $method === 'POST') {
  // normalisation légère (espaces multiples -> 1)
  $nom     = trim(preg_replace('/\s+/u',' ', (string)($_POST['nom'] ?? '')));
  $adresse = trim(preg_replace('/\s+/u',' ', (string)($_POST['adresse'] ?? '')));
  if ($nom === '') { bad_request('Nom requis'); }

  // Cherche si elle existe déjà (même entreprise, actif, casse/espaces ignorés)
  $chk = $pdo->prepare("
    SELECT id FROM agences
    WHERE entreprise_id=? AND actif=1 AND LOWER(TRIM(nom)) = LOWER(TRIM(?))
    LIMIT 1
  ");
  $chk->execute([$entrepriseId, $nom]);
  $existingId = (int)$chk->fetchColumn();

  if ($existingId) {
    // ✅ On considère que c’est un succès : on renvoie l'id existant
    echo json_encode(['ok'=>true, 'id'=>$existingId, 'existing'=>true, 'nom'=>$nom]);
    exit;
  }

  // Sinon on crée
  $ins = $pdo->prepare("INSERT INTO agences (entreprise_id, nom, adresse, actif) VALUES (?, ?, ?, 1)");
  $ins->execute([$entrepriseId, $nom, $adresse !== '' ? $adresse : null]);

  echo json_encode(['ok'=>true, 'id'=>(int)$pdo->lastInsertId(), 'existing'=>false, 'nom'=>$nom]);
  exit;
}


/* ====== UPDATE ====== */
if ($action === 'update' && $method === 'POST') {
  $id      = (int)($_POST['id'] ?? 0);
  $nom     = norm((string)($_POST['nom'] ?? ''));
  $adresse = norm((string)($_POST['adresse'] ?? ''));

  if (!$id || $nom==='') bad_request('Paramètres manquants');

  // appartenance + actif
  $own = $pdo->prepare("SELECT id FROM agences WHERE id=? AND entreprise_id=? AND actif=1");
  $own->execute([$id, $entrepriseId]);
  if (!$own->fetch()) { http_response_code(404); echo json_encode(['ok'=>false,'msg'=>'Introuvable']); exit; }

  // unicité normalisée sur autres lignes
  $chk = $pdo->prepare("
    SELECT id FROM agences
    WHERE entreprise_id=? AND actif=1 AND id<>? AND LOWER(TRIM(nom)) = LOWER(TRIM(?))
    LIMIT 1
  ");
  $chk->execute([$entrepriseId, $id, $nom]);
  if ($chk->fetch()) { http_response_code(409); echo json_encode(['ok'=>false,'msg'=>'Nom déjà utilisé']); exit; }

  $upd = $pdo->prepare("UPDATE agences SET nom=?, adresse=? WHERE id=?");
  $upd->execute([$nom, $adresse !== '' ? $adresse : null, $id]);

  echo json_encode(['ok'=>true]); exit;
}

/* ====== DELETE (soft) ====== */
if ($action === 'delete' && $method === 'POST') {
  $id = (int)($_POST['id'] ?? 0);
  if (!$id) bad_request('ID manquant');

  $del = $pdo->prepare("UPDATE agences SET actif=0 WHERE id=? AND entreprise_id=?");
  $del->execute([$id, $entrepriseId]);

  echo json_encode(['ok'=>true]); exit;
}

bad_request();
