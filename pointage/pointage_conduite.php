<?php
// /pointage/pointage_conduite.php
declare(strict_types=1);

ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json; charset=utf-8');

try {
  // --- Sécurité / Session ---
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_SESSION['utilisateurs'])) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Accès refusé'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $user         = $_SESSION['utilisateurs'];
  $entrepriseId = (int)($user['entreprise_id'] ?? 0);
  $role         = strtolower((string)($user['fonction'] ?? ''));
  $selfId       = (int)($user['id'] ?? 0);

  // --- Params ---
  $uidParam    = (int)($_POST['utilisateur_id'] ?? 0);
  $uid         = $uidParam > 0 ? $uidParam : $selfId;
  $chantierId  = (int)($_POST['chantier_id'] ?? 0);
  $date        = (string)($_POST['date_pointage'] ?? $_POST['date'] ?? ''); // accepte date_pointage ou date
  $type        = strtoupper(trim((string)($_POST['type'] ?? '')));
  $remove      = filter_var($_POST['remove'] ?? '0', FILTER_VALIDATE_BOOLEAN);

  // --- Validation de base ---
  if ($entrepriseId<=0 || $uid<=0 || $chantierId<=0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) || !in_array($type, ['A','R'], true)) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Paramètres invalides'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // ======== AUTORISATIONS (chef : affectation OU planning du jour, filtré par entreprise) ========
  $chefHasChantier = function(PDO $pdo, int $chefId, int $cId, string $d, int $eid): bool {
    // affectation longue
    $q1 = $pdo->prepare("
      SELECT 1
      FROM utilisateur_chantiers uc
      JOIN chantiers c ON c.id = uc.chantier_id AND c.entreprise_id = :e
      WHERE uc.utilisateur_id = :u AND uc.chantier_id = :c
      LIMIT 1
    ");
    $q1->execute([':u'=>$chefId, ':c'=>$cId, ':e'=>$eid]);
    if ($q1->fetchColumn()) return true;
    // planning du jour
    $q2 = $pdo->prepare("
      SELECT 1
      FROM planning_affectations pa
      JOIN chantiers c ON c.id = pa.chantier_id AND c.entreprise_id = :e
      WHERE pa.utilisateur_id = :u AND pa.chantier_id = :c AND pa.date_jour = :d
      LIMIT 1
    ");
    $q2->execute([':u'=>$chefId, ':c'=>$cId, ':d'=>$d, ':e'=>$eid]);
    return (bool)$q2->fetchColumn();
  };

  $empHasChantier = function(PDO $pdo, int $empId, int $cId, string $d, int $eid): bool {
    $q1 = $pdo->prepare("
      SELECT 1
      FROM utilisateur_chantiers uc
      JOIN chantiers c ON c.id = uc.chantier_id AND c.entreprise_id = :e
      WHERE uc.utilisateur_id = :u AND uc.chantier_id = :c
      LIMIT 1
    ");
    $q1->execute([':u'=>$empId, ':c'=>$cId, ':e'=>$eid]);
    if ($q1->fetchColumn()) return true;
    $q2 = $pdo->prepare("
      SELECT 1
      FROM planning_affectations pa
      JOIN chantiers c ON c.id = pa.chantier_id AND c.entreprise_id = :e
      WHERE pa.utilisateur_id = :u AND pa.chantier_id = :c AND pa.date_jour = :d
      LIMIT 1
    ");
    $q2->execute([':u'=>$empId, ':c'=>$cId, ':d'=>$d, ':e'=>$eid]);
    return (bool)$q2->fetchColumn();
  };

  if ($role !== 'administrateur') {
    if ($role === 'chef') {
      $okChef = $chefHasChantier($pdo, $selfId, $chantierId, $date, $entrepriseId);
      $okEmp  = $empHasChantier ($pdo, $uid,    $chantierId, $date, $entrepriseId);
      if (!$okChef || !$okEmp) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Salarié hors équipe'], JSON_UNESCAPED_UNICODE);
        exit;
      }
    } else {
      // employé / dépôt : uniquement eux-mêmes
      if ($uid !== $selfId) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Action non autorisée'], JSON_UNESCAPED_UNICODE);
        exit;
      }
    }
  }
  // ======== FIN AUTORISATIONS ========

  // --- Table conduite (si besoin) ---
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS pointages_conduite (
      id INT AUTO_INCREMENT PRIMARY KEY,
      entreprise_id INT NOT NULL,
      utilisateur_id INT NOT NULL,
      chantier_id INT NOT NULL,
      date_pointage DATE NOT NULL,
      type ENUM('A','R') NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      UNIQUE KEY uq_conduite (entreprise_id, utilisateur_id, chantier_id, date_pointage, type),
      KEY idx_ent_date (entreprise_id, date_pointage)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  // --- Suppression ---
  if ($remove) {
    $pdo->prepare("
      DELETE FROM pointages_conduite
      WHERE entreprise_id = ? AND utilisateur_id = ? AND chantier_id = ? AND date_pointage = ? AND type = ?
    ")->execute([$entrepriseId, $uid, $chantierId, $date, $type]);

    echo json_encode(['success'=>true,'removed'=>true], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // --- UPSERT (placeholders DISTINCTS pour éviter HY093) ---
  $stmt = $pdo->prepare("
    INSERT INTO pointages_conduite
      (entreprise_id, utilisateur_id, chantier_id, date_pointage, type, created_at, updated_at)
    VALUES
      (:e, :u, :c, :d, :t_ins, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
      updated_at = NOW()
  ");
  $stmt->execute([
    ':e'     => $entrepriseId,
    ':u'     => $uid,
    ':c'     => $chantierId,
    ':d'     => $date,
    ':t_ins' => $type
  ]);

  echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>'PHP: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
