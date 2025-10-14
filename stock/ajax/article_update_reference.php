<?php
// /stock/ajax/article_update_reference.php
declare(strict_types=1);
require_once __DIR__ . '/../../config/init.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function jexit(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

// Sécurité basique
if (empty($_SESSION['utilisateurs'])) {
  jexit(401, ['ok'=>false, 'msg'=>'Non authentifié.']);
}
$u = $_SESSION['utilisateurs'];
if (($u['fonction'] ?? '') !== 'administrateur') {
  jexit(403, ['ok'=>false, 'msg'=>'Accès refusé.']);
}
// (Optionnel) valider que c'est bien un appel AJAX
// if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
//   jexit(400, ['ok'=>false, 'msg'=>'Requête invalide.']);
// }

$entId     = (int)($u['entreprise_id'] ?? 0);
$articleId = isset($_POST['article_id']) ? (int)$_POST['article_id'] : 0;
$reference = trim((string)($_POST['reference'] ?? ''));
$reference = mb_substr($reference, 0, 100);

// Validations
if ($articleId <= 0)        jexit(422, ['ok'=>false, 'msg'=>'Article invalide.']);
if ($reference === '')      jexit(422, ['ok'=>false, 'msg'=>'La référence est obligatoire.']);
if ($entId <= 0)            jexit(422, ['ok'=>false, 'msg'=>"Entreprise invalide."]);

// Vérifier appartenance à l'entreprise
$st = $pdo->prepare('SELECT id FROM stock WHERE id = :id AND entreprise_id = :eid LIMIT 1');
$st->execute([':id' => $articleId, ':eid' => $entId]);
if (!$st->fetch()) {
  jexit(404, ['ok'=>false, 'msg'=>'Article introuvable.']);
}

// (Facultatif) unicité par entreprise
// $st = $pdo->prepare('SELECT id FROM stock WHERE reference = :ref AND entreprise_id = :eid AND id <> :id LIMIT 1');
// $st->execute([':ref'=>$reference, ':eid'=>$entId, ':id'=>$articleId]);
// if ($st->fetch()) jexit(409, ['ok'=>false, 'msg'=>'Cette référence existe déjà.']);

try {
  // IMPORTANT : seulement 3 placeholders et on passe EXACTEMENT 3 clés dans execute()
  $st = $pdo->prepare('
    UPDATE stock
       SET reference = :ref
     WHERE id = :id
       AND entreprise_id = :eid
     LIMIT 1
  ');
  $ok = $st->execute([
    ':ref' => $reference,
    ':id'  => $articleId,
    ':eid' => $entId,
  ]);

  if (!$ok) jexit(500, ['ok'=>false, 'msg'=>'Échec de la mise à jour.']);

  jexit(200, ['ok'=>true, 'reference'=>$reference]);
} catch (Throwable $e) {
  jexit(500, ['ok'=>false, 'msg'=>'Erreur: '.$e->getMessage()]);
}
