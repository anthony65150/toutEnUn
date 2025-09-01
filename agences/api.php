<?php
// /agences/api.php
declare(strict_types=1);
require_once __DIR__ . '/../config/init.php';
requireAuthApi();
header('Content-Type: application/json; charset=utf-8');

function json_ok(array $data = [], int $code = 200): void {
  http_response_code($code);
  echo json_encode(['ok' => true] + $data);
  exit;
}
function json_err(string $msg = 'Requête invalide', int $code = 400): void {
  http_response_code($code);
  echo json_encode(['ok' => false, 'msg' => $msg]);
  exit;
}

if (!isset($_SESSION['utilisateurs'])) {
  json_err('Non authentifié', 401);
}

$entrepriseId = (int)($_SESSION['entreprise_id'] ?? 0); // ✅ unifié avec agences.php
if ($entrepriseId <= 0) {
  json_err('Entreprise manquante', 400);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function ensure_csrf(): void {
  if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
    json_err('Token CSRF invalide', 400);
  }
}
function norm(string $s): string {
  return trim(preg_replace('/\s+/u', ' ', $s));
}

/* ====== LIST ====== */
if ($action === 'list' && $method === 'GET') {
  $q = norm((string)($_GET['q'] ?? ''));
  if ($q !== '') {
    $like = "%$q%";
    $stmt = $pdo->prepare("
      SELECT id, nom, adresse, actif
      FROM agences
      WHERE entreprise_id = ? AND actif = 1
        AND (LOWER(nom) LIKE LOWER(?) OR LOWER(IFNULL(adresse,'')) LIKE LOWER(?))
      ORDER BY nom
    ");
    $stmt->execute([$entrepriseId, $like, $like]);
  } else {
    $stmt = $pdo->prepare("
      SELECT id, nom, adresse, actif
      FROM agences
      WHERE entreprise_id = ? AND actif = 1
      ORDER BY nom
    ");
    $stmt->execute([$entrepriseId]);
  }
  json_ok(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ====== SHOW ====== */
if ($action === 'show' && $method === 'GET') {
  $id = (int)($_GET['id'] ?? 0);
  if (!$id) json_err();
  $stmt = $pdo->prepare("
    SELECT id, nom, adresse, actif
    FROM agences
    WHERE id = ? AND entreprise_id = ?
  ");
  $stmt->execute([$id, $entrepriseId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) json_err('Introuvable', 404);
  json_ok(['item' => $row]);
}

/* ====== POST actions avec CSRF ====== */
if (in_array($action, ['create', 'update', 'delete'], true)) ensure_csrf();

/* ------- CREATE ------- */
if ($action === 'create' && $method === 'POST') {
  $nom     = norm((string)($_POST['nom'] ?? ''));
  $adresse = norm((string)($_POST['adresse'] ?? ''));

  if ($nom === '') json_err('Nom requis');

  // Déjà existante ? (même entreprise, actif, casse/espaces ignorés)
  $chk = $pdo->prepare("
    SELECT id FROM agences
    WHERE entreprise_id = ? AND actif = 1 AND LOWER(TRIM(nom)) = LOWER(TRIM(?))
    LIMIT 1
  ");
  $chk->execute([$entrepriseId, $nom]);
  $existingId = (int)$chk->fetchColumn();

  if ($existingId) {
    json_ok(['id' => $existingId, 'existing' => true, 'nom' => $nom]);
  }

  // Création
  $ins = $pdo->prepare("
    INSERT INTO agences (entreprise_id, nom, adresse, actif)
    VALUES (?, ?, ?, 1)
  ");
  $ins->execute([$entrepriseId, $nom, $adresse !== '' ? $adresse : null]);

  json_ok(['id' => (int)$pdo->lastInsertId(), 'existing' => false, 'nom' => $nom], 201);
}

/* ------- UPDATE ------- */
if ($action === 'update' && $method === 'POST') {
  $id      = (int)($_POST['id'] ?? 0);
  $nom     = norm((string)($_POST['nom'] ?? ''));
  $adresse = norm((string)($_POST['adresse'] ?? ''));

  if (!$id || $nom === '') json_err('Paramètres manquants');

  // Appartenance + actif
  $own = $pdo->prepare("
    SELECT id FROM agences
    WHERE id = ? AND entreprise_id = ? AND actif = 1
  ");
  $own->execute([$id, $entrepriseId]);
  if (!$own->fetch()) json_err('Introuvable', 404);

  // Unicité du nom normalisé (autres lignes)
  $chk = $pdo->prepare("
    SELECT id FROM agences
    WHERE entreprise_id = ? AND actif = 1 AND id <> ?
      AND LOWER(TRIM(nom)) = LOWER(TRIM(?))
    LIMIT 1
  ");
  $chk->execute([$entrepriseId, $id, $nom]);
  if ($chk->fetch()) json_err('Nom déjà utilisé', 409);

  $upd = $pdo->prepare("UPDATE agences SET nom = ?, adresse = ? WHERE id = ? AND entreprise_id = ?");
  $upd->execute([$nom, $adresse !== '' ? $adresse : null, $id, $entrepriseId]);

  json_ok();
}

/* ------- DELETE (soft) ------- */
if ($action === 'delete' && $method === 'POST') {
  $id = (int)($_POST['id'] ?? 0);
  if (!$id) json_err('ID manquant');

  $del = $pdo->prepare("UPDATE agences SET actif = 0 WHERE id = ? AND entreprise_id = ?");
  $del->execute([$id, $entrepriseId]);

  json_ok();
}

json_err();
