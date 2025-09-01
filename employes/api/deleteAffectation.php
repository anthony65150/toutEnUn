<?php
require_once __DIR__ . '/../../config/init.php';
header('Content-Type: application/json');

if (!isset($_SESSION['utilisateurs']) || (($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur')) {
  http_response_code(403); echo json_encode(['ok'=>false,'message'=>'Non autorisé']); exit;
}

$entrepriseId = (int)($_SESSION['entreprise_id'] ?? 0);
$uid  = (int)($_POST['emp_id'] ?? 0);
$jour = $_POST['date'] ?? (new DateTime('today'))->format('Y-m-d');

if ($uid <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'message'=>'Employé manquant']); exit; }

/* Deux options :
   - soit on SUPPRIME la ligne du jour
   - soit on la garde avec chantier_id = NULL
   Ici je supprime pour faire simple.
*/
$st = $pdo->prepare("
  DELETE FROM planning_affectations
  WHERE utilisateur_id = :u
    AND date_jour      = :d
    AND entreprise_id  = :e
");
$ok = $st->execute([':u'=>$uid, ':d'=>$jour, ':e'=>$entrepriseId]);

echo json_encode(['ok'=>true]);
