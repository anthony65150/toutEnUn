<?php
// /pointage/pointage_absence.php
declare(strict_types=1);

ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json; charset=utf-8');

try {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_SESSION['utilisateurs'])) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Accès refusé'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $user         = $_SESSION['utilisateurs'];
  $entrepriseId = (int)($user['entreprise_id'] ?? 0);
  $role         = strtolower((string)($user['fonction'] ?? ''));
  $selfId       = (int)($user['id'] ?? 0);

  // Params
  $uidParam   = (int)($_POST['utilisateur_id'] ?? 0);
  $date       = (string)($_POST['date'] ?? ''); // YYYY-MM-DD
  // valeurs entrantes possibles : 'auto' | rtt | conges_payes | conges_intemperies | maladie | justifie | injustifie
  $reasonRaw  = strtolower(trim((string)($_POST['reason'] ?? '')));
  $remove     = filter_var($_POST['remove'] ?? '0', FILTER_VALIDATE_BOOLEAN);
  $hoursStr   = (string)($_POST['hours'] ?? '');
  $chantierId = isset($_POST['chantier_id']) ? (int)$_POST['chantier_id'] : null;

  $uid = $uidParam > 0 ? $uidParam : $selfId;

  // Validation
  if ($entrepriseId <= 0 || $uid <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Paramètres invalides'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  /* ======== SCHÉMA ======== */
  // Création si absent
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS pointages_absences (
      id INT AUTO_INCREMENT PRIMARY KEY,
      entreprise_id INT NOT NULL,
      utilisateur_id INT NOT NULL,
      date_jour DATE NOT NULL,
      motif ENUM('conges_payes','conges_intemperies','maladie','justifie','injustifie','rtt') NOT NULL,
      heures DECIMAL(5,2) DEFAULT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      UNIQUE KEY uq_abs (entreprise_id, utilisateur_id, date_jour),
      KEY idx_ent_date (entreprise_id, date_jour)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  // ALTER si ENUM sans rtt / valeurs manquantes
  $col = $pdo->query("SHOW COLUMNS FROM pointages_absences LIKE 'motif'")->fetch(PDO::FETCH_ASSOC);
  if ($col && isset($col['Type'])) {
    $type = strtolower($col['Type']);
    $needAlter = false;
    foreach (['conges_payes','conges_intemperies','maladie','justifie','injustifie','rtt'] as $val) {
      if (strpos($type, $val) === false) { $needAlter = true; break; }
    }
    if ($needAlter) {
      $pdo->exec("ALTER TABLE pointages_absences
        MODIFY motif ENUM('conges_payes','conges_intemperies','maladie','justifie','injustifie','rtt') NOT NULL");
    }
  }

  // Migration anciennes valeurs
  $pdo->exec("UPDATE pointages_absences SET motif='conges_payes' WHERE motif='conges'");
  $pdo->exec("UPDATE pointages_absences SET motif='justifie'      WHERE motif='autres'");

  /* ======== RÈGLES ======== */
  // Heures (0..8.25 par pas de 0.25)
  $hours = (float)str_replace(',', '.', $hoursStr);
  if ($hours < 0)    $hours = 0.0;
  if ($hours > 8.25) $hours = 8.25;
  if ($hours > 0)    $hours = round($hours/0.25)*0.25;

  // Autorisations (identiques à avant)
  $chefHasChantier = function(PDO $pdo, int $chefId, int $cId, string $d): bool {
    $q1 = $pdo->prepare("SELECT 1 FROM utilisateur_chantiers WHERE utilisateur_id=:u AND chantier_id=:c LIMIT 1");
    $q1->execute([':u'=>$chefId, ':c'=>$cId]);
    if ($q1->fetchColumn()) return true;
    $q2 = $pdo->prepare("SELECT 1 FROM planning_affectations WHERE utilisateur_id=:u AND chantier_id=:c AND date_jour=:d LIMIT 1");
    $q2->execute([':u'=>$chefId, ':c'=>$cId, ':d'=>$d]);
    return (bool)$q2->fetchColumn();
  };
  $empHasChantier = function(PDO $pdo, int $empId, int $cId, string $d): bool {
    $q1 = $pdo->prepare("SELECT 1 FROM utilisateur_chantiers WHERE utilisateur_id=:u AND chantier_id=:c LIMIT 1");
    $q1->execute([':u'=>$empId, ':c'=>$cId]);
    if ($q1->fetchColumn()) return true;
    $q2 = $pdo->prepare("SELECT 1 FROM planning_affectations WHERE utilisateur_id=:u AND chantier_id=:c AND date_jour=:d LIMIT 1");
    $q2->execute([':u'=>$empId, ':c'=>$cId, ':d'=>$d]);
    return (bool)$q2->fetchColumn();
  };
  $getEmpPlannedChantiers = function(PDO $pdo, int $entrepriseId, int $empId, string $d): array {
    $st = $pdo->prepare("SELECT chantier_id FROM planning_affectations WHERE entreprise_id=:e AND utilisateur_id=:u AND date_jour=:d");
    $st->execute([':e'=>$entrepriseId, ':u'=>$empId, ':d'=>$d]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
  };

  if ($role !== 'administrateur') {
    if ($role === 'chef') {
      if ($chantierId) {
        $okChef = $chefHasChantier($pdo, $selfId, (int)$chantierId, $date);
        $okEmp  = $empHasChantier ($pdo, $uid,    (int)$chantierId, $date);
        if (!$okChef || !$okEmp) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Salarié hors équipe']); exit; }
      } else {
        $hasCommon = $pdo->prepare("
          SELECT 1
          FROM utilisateur_chantiers ucChef
          JOIN utilisateur_chantiers ucEmp ON ucEmp.chantier_id = ucChef.chantier_id
          WHERE ucChef.utilisateur_id = :chef AND ucEmp.utilisateur_id = :emp
          LIMIT 1
        ");
        $hasCommon->execute([':chef'=>$selfId, ':emp'=>$uid]);
        if (!$hasCommon->fetchColumn()) {
          $planned = $getEmpPlannedChantiers($pdo, $entrepriseId, $uid, $date);
          $ok = false; foreach ($planned as $cid) { if ($chefHasChantier($pdo, $selfId, $cid, $date)) { $ok = true; break; } }
          if (!$ok) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Salarié hors équipe']); exit; }
        }
      }
    } else {
      if ($uid !== $selfId) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Action non autorisée']); exit; }
    }
  }

  /* ======== INFOS PLANNING & DÉDUCTION AUTOMATIQUE ======== */
  $planningType = null; // 'rtt' | 'conges' | 'maladie' | 'chantier' | 'depot' | null
  $stPlan = $pdo->prepare("
    SELECT type FROM planning_affectations
    WHERE entreprise_id=:e AND utilisateur_id=:u AND date_jour=:d
    LIMIT 1
  ");
  $stPlan->execute([':e'=>$entrepriseId, ':u'=>$uid, ':d'=>$date]);
  $planningType = strtolower((string)($stPlan->fetchColumn() ?: ''));

  // Normalise 'reason'
  $reason = $reasonRaw;
  if ($reason === '' || $reason === 'auto') {
    // Déduire depuis le planning
    if ($planningType === 'rtt')        $reason = 'rtt';
    elseif ($planningType === 'maladie') $reason = 'maladie';
    elseif ($planningType === 'conges')  $reason = 'conges_payes';
  }

  // Motifs valides (inclut maintenant rtt)
  $validReasons = ['conges_payes','conges_intemperies','maladie','justifie','injustifie','rtt'];
  if (!in_array($reason, $validReasons, true)) $reason = 'injustifie';

  /* ======== SUPPRESSION ======== */
  if ($remove || $hours === 0.0) {
    $pdo->prepare("DELETE FROM pointages_absences WHERE entreprise_id=? AND utilisateur_id=? AND date_jour=?")
        ->execute([$entrepriseId, $uid, $date]);
    echo json_encode(['success'=>true,'removed'=>true,'planning_type'=>$planningType ?: null], JSON_UNESCAPED_UNICODE);
    exit;
  }

  /* ======== CONSÉQUENCE : on nettoie présence & conduite ======== */
  $pdo->prepare("DELETE FROM pointages_jour WHERE entreprise_id=? AND utilisateur_id=? AND date_jour=?")
      ->execute([$entrepriseId, $uid, $date]);
  $pdo->prepare("DELETE FROM pointages_conduite WHERE entreprise_id=? AND utilisateur_id=? AND date_pointage=?")
      ->execute([$entrepriseId, $uid, $date]);

  /* ======== UPSERT ABSENCE ======== */
  $stmt = $pdo->prepare("
    INSERT INTO pointages_absences
      (entreprise_id, utilisateur_id, date_jour, motif, heures, created_at, updated_at)
    VALUES
      (:e, :u, :d, :m_ins, :h_ins, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
      motif      = :m_upd,
      heures     = :h_upd,
      updated_at = NOW()
  ");
  $stmt->execute([
    ':e'     => $entrepriseId,
    ':u'     => $uid,
    ':d'     => $date,
    ':m_ins' => $reason,
    ':h_ins' => $hours,
    ':m_upd' => $reason,
    ':h_upd' => $hours,
  ]);

  echo json_encode([
    'success'       => true,
    'reason'        => $reason,
    'hours'         => (float)$hours,
    'planning_type' => $planningType ?: null
  ], JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>'PHP: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
