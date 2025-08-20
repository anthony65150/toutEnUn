<?php
require_once __DIR__ . '/config/init.php';
header('Content-Type: application/json');

// --- Toujours capturer les fatales en JSON ---
ini_set('display_errors', '0');
error_reporting(E_ALL);
register_shutdown_function(function() {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'message'=>'Erreur fatale: '.$e['message']]);
  }
});

// --- Sécurité session/role ---
if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur') {
  echo json_encode(['success' => false, 'message' => 'Accès refusé']); exit;
}

// --- CSRF ---
$action = $_POST['action'] ?? null;
$csrf   = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
  echo json_encode(['success' => false, 'message' => 'CSRF invalide']); exit;
}

// --- Helpers sans mbstring ---
function lower_str($s) {
  if (function_exists('mb_strtolower')) return mb_strtolower($s, 'UTF-8');
  return strtolower($s);
}
function normalize_role($role) {
  $r = lower_str(trim($role));
  // enlever accents communs vers ascii pour fiabiliser
  $r = strtr($r, ['é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','à'=>'a','â'=>'a','î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c']);
  return $r;
}

$ROLE_OPTIONS = [
  'administrateur' => 'Administrateur',
  'depot'          => 'Dépôt',
  'chef'           => 'Chef',
  'employe'        => 'Employé',
  'autre'          => 'Autre',
];

function sanitize_role($role, $allowed) {
  $r = normalize_role($role);
  return array_key_exists($r, $allowed) ? $r : 'autre';
}

function badgeRole($role) {
  $r = sanitize_role($role, [
    'administrateur'=>1,'depot'=>1,'chef'=>1,'employe'=>1,'autre'=>1
  ]);
  switch ($r) {
    case 'administrateur': return '<span class="badge bg-danger">Administrateur</span>';
    case 'depot':          return '<span class="badge bg-info text-dark">Dépôt</span>';
    case 'chef':           return '<span class="badge bg-success">Chef</span>';
    case 'employe':        return '<span class="badge bg-warning text-dark">Employé</span>';
    default:               return '<span class="badge bg-secondary">Autre</span>';
  }
}

function rowHtml($u) {
  $id       = (int)$u['id'];
  $nom      = htmlspecialchars($u['nom']);
  $prenom   = htmlspecialchars($u['prenom']);
  $email    = htmlspecialchars($u['email'] ?? '');
  $fonction = htmlspecialchars($u['fonction']);
  return '
    <tr data-id="'.$id.'"
        data-nom="'.$nom.'"
        data-prenom="'.$prenom.'"
        data-email="'.$email.'"
        data-fonction="'.$fonction.'">
      <td>'.$id.'</td>
      <td><strong>'.$nom.' '.$prenom.'</strong></td>
      <td>'.$email.'</td>
      <td>'.badgeRole($fonction).'</td>
      <td class="text-center">
        <button class="btn btn-warning btn-sm edit-btn" title="Modifier"><i class="bi bi-pencil"></i></button>
        <button class="btn btn-danger btn-sm delete-btn" title="Supprimer"><i class="bi bi-trash"></i></button>
      </td>
    </tr>';
}

try {
  if ($action === 'create') {
    $prenom   = trim($_POST['prenom'] ?? '');
    $nom      = trim($_POST['nom'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $fonction = sanitize_role($_POST['fonction'] ?? 'autre', $ROLE_OPTIONS);
    $password = $_POST['password'] ?? '';

    if ($prenom === '' || $nom === '' || $email === '' || $fonction === '') {
      throw new Exception("Champs obligatoires manquants.");
    }
    if ($password === '') {
      throw new Exception("Mot de passe requis.");
    }

    // unicité email
    $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) throw new Exception("Email déjà utilisé.");

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("INSERT INTO utilisateurs (prenom, nom, email, motDePasse, fonction) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$prenom, $nom, $email, $hash, $fonction]);

    $id = (int)$pdo->lastInsertId();

    $u = $pdo->prepare("SELECT id, nom, prenom, email, fonction FROM utilisateurs WHERE id=?");
    $u->execute([$id]);
    $user = $u->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success'=>true,'id'=>$id,'rowHtml'=>rowHtml($user)]); exit;
  }

  if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) throw new Exception("ID invalide.");

    $prenom   = trim($_POST['prenom'] ?? '');
    $nom      = trim($_POST['nom'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $fonction = sanitize_role($_POST['fonction'] ?? 'autre', $ROLE_OPTIONS);
    $password = $_POST['password'] ?? '';

    if ($prenom === '' || $nom === '' || $email === '' || $fonction === '') {
      throw new Exception("Champs obligatoires manquants.");
    }

    // unicité email (autres IDs)
    $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = ? AND id <> ?");
    $stmt->execute([$email, $id]);
    if ($stmt->fetch()) throw new Exception("Email déjà utilisé par un autre utilisateur.");

    if ($password !== '') {
      if (strlen($password) < 6) throw new Exception("Mot de passe trop court.");
      $hash = password_hash($password, PASSWORD_DEFAULT);
      $sql = "UPDATE utilisateurs SET prenom=?, nom=?, email=?, fonction=?, motDePasse=? WHERE id=?";
      $params = [$prenom, $nom, $email, $fonction, $hash, $id];
    } else {
      $sql = "UPDATE utilisateurs SET prenom=?, nom=?, email=?, fonction=? WHERE id=?";
      $params = [$prenom, $nom, $email, $fonction, $id];
    }

    $upd = $pdo->prepare($sql);
    $upd->execute($params);

    $u = $pdo->prepare("SELECT id, nom, prenom, email, fonction FROM utilisateurs WHERE id=?");
    $u->execute([$id]);
    $user = $u->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success'=>true,'id'=>$id,'rowHtml'=>rowHtml($user)]); exit;
  }

  if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) throw new Exception("ID invalide.");

    $del = $pdo->prepare("DELETE FROM utilisateurs WHERE id=?");
    $del->execute([$id]);

    echo json_encode(['success'=>true,'id'=>$id]); exit;
  }

  echo json_encode(['success'=>false,'message'=>'Action inconnue.']);
} catch (Exception $e) {
  echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
