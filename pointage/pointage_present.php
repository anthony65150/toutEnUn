<?php
// /pointage/pointage_present.php
declare(strict_types=1);
ini_set('display_errors','1'); ini_set('display_startup_errors','1'); error_reporting(E_ALL);
require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json; charset=utf-8');

try {
  // --- Sécurité minimale ---
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_SESSION['utilisateurs'])) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Accès refusé']);
    exit;
  }

  $u = $_SESSION['utilisateurs'];
  $entrepriseId = (int)($u['entreprise_id'] ?? 0);
  $role         = (string)($u['fonction'] ?? '');
  $selfId       = (int)($u['id'] ?? 0);

  // --- Params ---
  $uidParam   = (int)($_POST['utilisateur_id'] ?? 0);
  $date       = (string)($_POST['date'] ?? '');
  $hoursStr   = (string)($_POST['hours'] ?? '0');
  $chantierId = isset($_POST['chantier_id']) ? (int)$_POST['chantier_id'] : null;

  $uid = $uidParam > 0 ? $uidParam : $selfId;

  // --- Validations ---
  if ($entrepriseId<=0 || $uid<=0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Paramètres invalides']);
    exit;
  }

  // --- Autorisations ---
  if ($role !== 'administrateur') {
    if ($role === 'chef') {
      // 1) Chef & salarié partagent au moins un chantier ?
      $hasCommon = $pdo->prepare("
        SELECT 1
        FROM utilisateur_chantiers ucChef
        JOIN utilisateur_chantiers ucEmp ON ucEmp.chantier_id = ucChef.chantier_id
        WHERE ucChef.utilisateur_id = :chef AND ucEmp.utilisateur_id = :emp
        LIMIT 1
      ");
      $hasCommon->execute([':chef'=>$selfId, ':emp'=>$uid]);

      if (!$hasCommon->fetchColumn()) {
        // 2) Sinon : autoriser si le salarié est planifié et que le chef a ce chantier
        $plannedEmp = $pdo->prepare("
          SELECT pa.chantier_id
          FROM planning_affectations pa
          JOIN chantiers c ON c.id = pa.chantier_id AND c.entreprise_id = :eid
          WHERE pa.utilisateur_id = :emp AND pa.date_jour = :d
        ");
        $plannedEmp->execute([':eid'=>$entrepriseId, ':emp'=>$uid, ':d'=>$date]);
        $plannedList = $plannedEmp->fetchAll(PDO::FETCH_COLUMN);

        if ($plannedList && count($plannedList) > 0) {
          $chefHasThatDay = $pdo->prepare("
            SELECT 1
            FROM utilisateur_chantiers uc
            WHERE uc.utilisateur_id = :chef
              AND uc.chantier_id IN (" . implode(',', array_map('intval',$plannedList)) . ")
            LIMIT 1
          ");
          $chefHasThatDay->execute([':chef'=>$selfId]);

          if (!$chefHasThatDay->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['success'=>false,'message'=>'Salarié hors équipe']);
            exit;
          }
        } else {
          http_response_code(403);
          echo json_encode(['success'=>false,'message'=>'Salarié hors équipe']);
          exit;
        }
      }
    } else {
      // employé / dépôt : uniquement eux-mêmes
      if ($uid !== $selfId) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Action non autorisée']);
        exit;
      }
    }
  }

  // --- Normaliser heures (0..8.25 par pas de 0.25) ---
  $h = (float)str_replace(',', '.', $hoursStr);
  if ($h < 0) $h = 0.0;
  if ($h > 8.25) $h = 8.25;
  if ($h > 0) $h = round($h / 0.25) * 0.25;

  // --- Si absence planifiée (RTT / Congés / Maladie), on interdit la présence pour non-admin ---
  // (Admin peut forcer une présence si besoin opérationnel.)
  $planningType = null;
  $stmtPlan = $pdo->prepare("
    SELECT type
    FROM planning_affectations
    WHERE entreprise_id = :e AND utilisateur_id = :u AND date_jour = :d
    LIMIT 1
  ");
  $stmtPlan->execute([':e'=>$entrepriseId, ':u'=>$uid, ':d'=>$date]);
  $planningType = strtolower((string)($stmtPlan->fetchColumn() ?: ''));

  $isAbsencePlanned = in_array($planningType, ['conges','maladie','rtt'], true);

  if ($h > 0 && $isAbsencePlanned && $role !== 'administrateur') {
    http_response_code(409);
    echo json_encode([
      'success'       => false,
      'message'       => "Jour planifié en " . strtoupper($planningType) . " — présence non autorisée.",
      'planning_type' => $planningType
    ]);
    exit;
  }

  // --- 0h => suppression ---
  if ($h === 0.0) {
    $pdo->prepare("DELETE FROM pointages_jour WHERE entreprise_id=? AND utilisateur_id=? AND date_jour=?")
        ->execute([$entrepriseId, $uid, $date]);
    echo json_encode(['success'=>true,'removed'=>true,'hours'=>0.0, 'planning_type'=>$planningType ?: null]);
    exit;
  }

  // --- UPSERT présence (placeholders DISTINCTS) ---
  $stmt = $pdo->prepare("
    INSERT INTO pointages_jour
      (entreprise_id, utilisateur_id, date_jour, chantier_id, heures, updated_at)
    VALUES
      (:e, :u, :d, :c_ins, :h_ins, NOW())
    ON DUPLICATE KEY UPDATE
      heures      = :h_upd,
      chantier_id = COALESCE(:c_upd, chantier_id),
      updated_at  = NOW()
  ");
  $cVal = $chantierId ?: null; // NULL si non fourni
  $stmt->execute([
    ':e'     => $entrepriseId,
    ':u'     => $uid,
    ':d'     => $date,
    ':c_ins' => $cVal,
    ':h_ins' => $h,
    ':h_upd' => $h,
    ':c_upd' => $cVal,
  ]);

  echo json_encode(['success'=>true,'hours'=>$h, 'planning_type'=>$planningType ?: null]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>'PHP: '.$e->getMessage()]);
  exit;
}
