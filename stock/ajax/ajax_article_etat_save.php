<?php
declare(strict_types=1);

// /stock/ajax/ajax_article_etat_save.php
require_once __DIR__ . '/../../config/init.php';

header('Content-Type: application/json; charset=utf-8');
// Ne jamais afficher les notices/warnings dans la réponse JSON
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function jexit(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

// --- Helper sécurité entreprise
function article_by_id(PDO $pdo, int $id): ?array {
  $st = $pdo->prepare("SELECT id, entreprise_id, maintenance_mode, compteur_heures FROM stock WHERE id=:id LIMIT 1");
  $st->execute([':id'=>$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

// --- Paramètres
$actionRaw  = (string)($_POST['action'] ?? '');
$articleId  = (int)($_POST['article_id'] ?? ($_POST['stock_id'] ?? 0));
$valeurInt  = isset($_POST['valeur_int']) ? (int)$_POST['valeur_int'] : null; // pour compteur_maj
$hours      = isset($_POST['hours']) ? (int)$_POST['hours'] : null;           // pour hour_meter (QR public)
$comment    = trim((string)($_POST['commentaire'] ?? ($_POST['message'] ?? '')));

// Unifie les actions (alias côté QR public)
$actionMap = [
  'hour_meter'      => 'compteur_maj',
  'declare_problem' => 'declarer_panne',
  'resolve_problem' => 'declarer_ok',
  'resolve_one'     => 'resolve_one',
];
$action = $actionMap[$actionRaw] ?? $actionRaw;

// --- Auth utilisateur (connecté) ou QR public
$isLogged = isset($_SESSION['utilisateurs']);
$uid      = $isLogged ? (int)$_SESSION['utilisateurs']['id'] : 0;
$entId    = $isLogged ? (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0) : 0;

// Option : autoriser QR public pour declare_problem / hour_meter / resolve_problem ?
$allowPublicQR = in_array($action, ['declarer_panne','compteur_maj','declarer_ok'], true);

// Si non connecté et action non autorisée, refuse
if (!$isLogged && !$allowPublicQR) {
  jexit(401, ['ok'=>false, 'msg'=>'Authentification requise']);
}

if ($articleId <= 0) {
  jexit(400, ['ok'=>false, 'msg'=>'article_id manquant']);
}

$art = article_by_id($pdo, $articleId);
if (!$art) {
  jexit(404, ['ok'=>false, 'msg'=>"Article introuvable"]);
}

// Si connecté : vérifie entreprise
if ($isLogged && $entId > 0 && (int)$art['entreprise_id'] !== $entId) {
  jexit(403, ['ok'=>false, 'msg'=>"Article hors de votre entreprise"]);
}

// Profil d'entretien
$maintenanceMode = (string)($art['maintenance_mode'] ?? 'none');
$profil = $maintenanceMode === 'hour_meter' ? 'compteur_heures' : ($maintenanceMode === 'electrical' ? 'autre' : 'aucun');

// --- Gestion upload optionnel
$fichierPath = null;
if (!empty($_FILES['fichier']) && is_uploaded_file($_FILES['fichier']['tmp_name'])) {
  // Whitelist simple
  $allowed = ['image/jpeg','image/png','image/webp','application/pdf'];
  $mime = mime_content_type($_FILES['fichier']['tmp_name']) ?: '';
  if (!in_array($mime, $allowed, true)) {
    jexit(400, ['ok'=>false, 'msg'=>'Type de fichier non autorisé']);
  }
  // Dossier uploads à la racine du projet
  $baseDir = dirname(__DIR__, 2) . '/uploads/etat/'; // <- /uploads/etat/
  if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true)) {
    jexit(500, ['ok'=>false, 'msg'=>'Impossible de créer le dossier de stockage']);
  }
  $ext  = pathinfo($_FILES['fichier']['name'], PATHINFO_EXTENSION);
  $name = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . ($ext ? '.' . strtolower($ext) : '');
  $dest = $baseDir . $name;
  if (!move_uploaded_file($_FILES['fichier']['tmp_name'], $dest)) {
    jexit(500, ['ok'=>false, 'msg'=>'Upload échoué']);
  }
  $fichierPath = 'uploads/etat/' . $name; // chemin web relatif
}

try {
  $pdo->beginTransaction();

  // Normalise les valeurs selon l'action
  if ($action === 'compteur_maj') {
    // utilise "hours" si présent (QR), sinon "valeur_int"
    $val = $hours ?? $valeurInt;
    if (!is_int($val)) {
      jexit(400, ['ok'=>false, 'msg'=>'Valeur du compteur manquante']);
    }
    if ($maintenanceMode !== 'hour_meter') {
      jexit(400, ['ok'=>false, 'msg'=>"Cet article n'est pas en mode compteur d'heures"]);
    }
    // Met à jour le compteur dans stock
    $u = $pdo->prepare("UPDATE stock SET compteur_heures=:v WHERE id=:id");
    $u->execute([':v'=>$val, ':id'=>$articleId]);
  }

  if ($action === 'declarer_panne') {
    if ($maintenanceMode !== 'electrical') {
      // OK si tu veux autoriser panne sur tout type, sinon :
      // jexit(400, ['ok'=>false, 'msg'=>"Cet article n'est pas en mode 'electrical'"]);
    }
    if ($comment === '') {
      jexit(400, ['ok'=>false, 'msg'=>'Description obligatoire']);
    }
    // Flag panne
    $u = $pdo->prepare("UPDATE stock SET panne=1 WHERE id=:id");
    $u->execute([':id'=>$articleId]);

    // Optionnel : créer alerte
    $a = $pdo->prepare("INSERT INTO stock_alerts (stock_id, message, is_read) VALUES (:sid,:msg,0)");
    $a->execute([':sid'=>$articleId, ':msg'=>$comment]);
  }

  if ($action === 'declarer_ok') {
    // Déflag panne & marquer alertes lues
    $pdo->prepare("UPDATE stock SET panne=0 WHERE id=:id")->execute([':id'=>$articleId]);
    $pdo->prepare("UPDATE stock_alerts SET is_read=1 WHERE stock_id=:sid AND is_read=0")->execute([':sid'=>$articleId]);
  }

  if ($action === 'resolve_one') {
  $alertId = (int)($_POST['alert_id'] ?? 0);
  if ($alertId <= 0) jexit(400, ['ok'=>false,'msg'=>'alert_id manquant']);

  // marquer l’alerte spécifique comme lue
  $u = $pdo->prepare("UPDATE stock_alerts SET is_read=1 WHERE id=:id AND stock_id=:sid");
  $u->execute([':id'=>$alertId, ':sid'=>$articleId]);

  // si plus aucune alerte non lue, repasse l’article en OK
  $q = $pdo->prepare("SELECT COUNT(*) FROM stock_alerts WHERE stock_id=:sid AND is_read=0");
  $q->execute([':sid'=>$articleId]);
  $remain = (int)$q->fetchColumn();
  if ($remain === 0) {
    $pdo->prepare("UPDATE stock SET panne=0 WHERE id=:id")->execute([':id'=>$articleId]);
  }

  // log historique
  $i = $pdo->prepare("INSERT INTO article_etats (entreprise_id, article_id, profil_qr, action, valeur_int, commentaire, fichier, created_by)
                      VALUES (:eid,:aid,:profil,'declarer_ok',NULL,:com,NULL,:uid)");
  $i->execute([
    ':eid'=>(int)$art['entreprise_id'], ':aid'=>$articleId, ':profil'=>$profil,
    ':com'=>'Alerte #'.$alertId.' résolue', ':uid'=>($uid ?: null)
  ]);

  $pdo->commit();
  jexit(200, ['ok'=>true]);
}

  // Historique (table personnalisée — adapte le nom si différent)
  $ins = $pdo->prepare("
    INSERT INTO article_etats
      (entreprise_id, article_id, profil_qr, action, valeur_int, commentaire, fichier, created_by)
    VALUES
      (:eid, :aid, :profil, :action, :val, :com, :file, :uid)
  ");
  $ins->execute([
    ':eid'   => (int)$art['entreprise_id'],
    ':aid'   => $articleId,
    ':profil'=> $profil,
    ':action'=> $action,
    ':val'   => ($action === 'compteur_maj') ? ($hours ?? $valeurInt) : null,
    ':com'   => ($comment !== '' ? $comment : null),
    ':file'  => $fichierPath,
    ':uid'   => ($uid ?: null), // public QR: peut être null
  ]);

  $pdo->commit();
  jexit(200, ['ok'=>true]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('ajax_article_etat_save: '.$e->getMessage());
  jexit(500, ['ok'=>false, 'msg'=>'Erreur interne']);
}


