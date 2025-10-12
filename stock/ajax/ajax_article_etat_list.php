<?php
// /stock/ajax/ajax_article_etat_list.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/init.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$articleId = (int)($_GET['article_id'] ?? 0);
if ($articleId <= 0) {
  echo json_encode(['ok' => false, 'rows' => [], 'msg' => 'article_id manquant']);
  exit;
}

$entId = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);

try {
  $sql = "
    SELECT
      ae.id, ae.article_id, ae.profil_qr, ae.action, ae.valeur_int,
      ae.commentaire, ae.fichier, ae.created_at,
      u.prenom, u.nom
    FROM article_etats ae
    LEFT JOIN utilisateurs u ON u.id = ae.created_by
    JOIN stock s ON s.id = ae.article_id
    WHERE ae.article_id = :aid
  ";
  $params = [':aid' => $articleId];
  if ($entId > 0) {
    $sql .= " AND s.entreprise_id = :eid";
    $params[':eid'] = $entId;
  }
  $sql .= " ORDER BY ae.created_at DESC, ae.id DESC LIMIT 200";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  error_log('ajax_article_etat_list: '.$e->getMessage());
  http_response_code(500);
  echo json_encode(['ok' => false, 'rows' => [], 'msg' => 'Erreur interne']);
}
