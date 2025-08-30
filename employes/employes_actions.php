<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', '0');
error_reporting(E_ALL);
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erreur fatale: ' . $e['message']]);
    }
});

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
    exit;
}

if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès refusé']);
    exit;
}

$action = $_POST['action'] ?? null;
$csrf   = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'CSRF invalide']);
    exit;
}

function lower_str($s) {
    if (function_exists('mb_strtolower')) return mb_strtolower((string)$s, 'UTF-8');
    return strtolower((string)$s);
}
function normalize_role($role) {
    $r = lower_str(trim((string)$role));
    $r = strtr($r, [
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'à' => 'a', 'â' => 'a',
        'î' => 'i', 'ï' => 'i',
        'ô' => 'o', 'ö' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c'
    ]);
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
        'administrateur' => 1, 'depot' => 1, 'chef' => 1, 'employe' => 1, 'autre' => 1
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
    $nom      = htmlspecialchars((string)($u['nom'] ?? ''), ENT_QUOTES, 'UTF-8');
    $prenom   = htmlspecialchars((string)($u['prenom'] ?? ''), ENT_QUOTES, 'UTF-8');
    $email    = htmlspecialchars((string)($u['email'] ?? ''), ENT_QUOTES, 'UTF-8');
    $fonction = htmlspecialchars((string)($u['fonction'] ?? ''), ENT_QUOTES, 'UTF-8');
    $agenceId = (int)($u['agence_id'] ?? 0);

    return '
    <tr data-id="'.$id.'"
        data-nom="'.$nom.'"
        data-prenom="'.$prenom.'"
        data-email="'.$email.'"
        data-fonction="'.$fonction.'"
        data-agence-id="'.$agenceId.'">
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
    $entrepriseId = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);

    // ---------- CREATE ----------
    if ($action === 'create') {
        $prenom    = trim((string)($_POST['prenom'] ?? ''));
        $nom       = trim((string)($_POST['nom'] ?? ''));
        $email     = trim((string)($_POST['email'] ?? ''));
        $fonction  = sanitize_role($_POST['fonction'] ?? 'autre', $ROLE_OPTIONS);
        $password  = (string)($_POST['password'] ?? '');
        $agence_id = isset($_POST['agence_id']) && $_POST['agence_id'] !== '' ? (int)$_POST['agence_id'] : null;

        if ($prenom === '' || $nom === '' || $email === '' || $fonction === '') {
            throw new Exception('Champs obligatoires manquants.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Email invalide.');
        }
        if ($password === '') {
            throw new Exception('Mot de passe requis.');
        }

        // Vérifier agence si fournie
        if ($agence_id !== null) {
            $chk = $pdo->prepare("SELECT 1 FROM agences WHERE id=? AND entreprise_id=? AND actif=1");
            $chk->execute([$agence_id, $entrepriseId]);
            if (!$chk->fetch()) $agence_id = null;
        }

        $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new Exception('Email déjà utilisé.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            INSERT INTO utilisateurs (prenom, nom, email, motDePasse, fonction, entreprise_id, agence_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$prenom, $nom, $email, $hash, $fonction, $entrepriseId, $agence_id]);

        $id = (int)$pdo->lastInsertId();

        $u = $pdo->prepare("SELECT id, nom, prenom, email, fonction, agence_id FROM utilisateurs WHERE id = ?");
        $u->execute([$id]);
        $user = $u->fetch(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'id' => $id, 'rowHtml' => rowHtml($user)]);
        exit;
    }

    // ---------- UPDATE ----------
    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new Exception('ID invalide.');

        $prenom    = trim((string)($_POST['prenom'] ?? ''));
        $nom       = trim((string)($_POST['nom'] ?? ''));
        $email     = trim((string)($_POST['email'] ?? ''));
        $fonction  = sanitize_role($_POST['fonction'] ?? 'autre', $ROLE_OPTIONS);
        $password  = (string)($_POST['password'] ?? '');
        $agence_id = isset($_POST['agence_id']) && $_POST['agence_id'] !== '' ? (int)$_POST['agence_id'] : null;

        if ($prenom === '' || $nom === '' || $email === '' || $fonction === '') {
            throw new Exception('Champs obligatoires manquants.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Email invalide.');
        }

        if ($agence_id !== null) {
            $chk = $pdo->prepare("SELECT 1 FROM agences WHERE id=? AND entreprise_id=? AND actif=1");
            $chk->execute([$agence_id, $entrepriseId]);
            if (!$chk->fetch()) $agence_id = null;
        }

        $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = ? AND id <> ?");
        $stmt->execute([$email, $id]);
        if ($stmt->fetch()) {
            throw new Exception('Email déjà utilisé par un autre utilisateur.');
        }

        if ($password !== '') {
            if (strlen($password) < 6) {
                throw new Exception('Mot de passe trop court (min 6).');
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $sql = "UPDATE utilisateurs SET prenom=?, nom=?, email=?, fonction=?, motDePasse=?, agence_id=? WHERE id=?";
            $params = [$prenom, $nom, $email, $fonction, $hash, $agence_id, $id];
        } else {
            $sql = "UPDATE utilisateurs SET prenom=?, nom=?, email=?, fonction=?, agence_id=? WHERE id=?";
            $params = [$prenom, $nom, $email, $fonction, $agence_id, $id];
        }

        $upd = $pdo->prepare($sql);
        $upd->execute($params);

        $u = $pdo->prepare("SELECT id, nom, prenom, email, fonction, agence_id FROM utilisateurs WHERE id = ?");
        $u->execute([$id]);
        $user = $u->fetch(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'id' => $id, 'rowHtml' => rowHtml($user)]);
        exit;
    }

    // ---------- DELETE ----------
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new Exception('ID invalide.');

        $del = $pdo->prepare("DELETE FROM utilisateurs WHERE id = ?");
        $del->execute([$id]);

        echo json_encode(['success' => true, 'id' => $id]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Action inconnue.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
