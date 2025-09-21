<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/init.php';
header('Content-Type: application/json; charset=utf-8');

// Ne jamais afficher d'erreurs HTML (elles casseraient le JSON)
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Tout warning/notice devient une exception → on garde un JSON propre
set_error_handler(function ($severity, $message, $file, $line) {
  throw new ErrorException($message, 0, $severity, $file, $line);
});

// Petit helper pour sortir proprement
function jexit(int $status, array $payload): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  // --- Sécurité session ---
  if (!isset($_SESSION['utilisateurs'])) {
    jexit(401, ['success' => false, 'message' => 'Non connecté']);
  }

  $u            = $_SESSION['utilisateurs'];
  $entrepriseId = (int)($u['entreprise_id'] ?? 0);
  if ($entrepriseId <= 0) {
    jexit(403, ['success' => false, 'message' => 'Entreprise inconnue']);
  }

  // --- Lecture entrée : JSON prioritaire, sinon POST/FormData ---
  $raw  = file_get_contents('php://input') ?: '';
  $body = json_decode($raw, true);
  if (!is_array($body) || !$body) {
    $body = $_POST;
  }

  // --- CSRF ---
  $csrf = (string)($body['csrf_token'] ?? '');
  if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
    jexit(400, ['success' => false, 'message' => 'CSRF invalide']);
  }

  // --- Inputs & normalisation ---
  $tacheId    = (int)($body['tache_id'] ?? 0);
  $chantierId = (int)($body['chantier_id'] ?? 0);

  // accepte éventuellement "task_name"
  $nom      = trim((string)($body['nom'] ?? $body['task_name'] ?? ''));
  $shortcut = trim((string)($body['shortcut'] ?? ''));
  $unite    = trim((string)($body['unite'] ?? ''));
  $quantite = (float)($body['quantite'] ?? 0);
  $tuH      = (float)($body['tu_heures'] ?? 0);

  if ($chantierId <= 0 || $nom === '') {
    jexit(400, ['success' => false, 'message' => 'Paramètres manquants']);
  }
  if ($quantite < 0 || $tuH < 0) {
    jexit(422, ['success' => false, 'message' => 'Valeurs négatives interdites']);
  }

  // --- Vérifie que le chantier appartient à l'entreprise en session ---
  $stChk = $pdo->prepare('SELECT 1 FROM chantiers WHERE id = ? AND entreprise_id = ? LIMIT 1');
  $stChk->execute([$chantierId, $entrepriseId]);
  if (!$stChk->fetchColumn()) {
    jexit(403, ['success' => false, 'message' => 'Accès interdit à ce chantier']);
  }

  // --- Upsert ---
  if ($tacheId <= 0) {
    $sql = 'INSERT INTO chantier_taches
            (entreprise_id, chantier_id, nom, shortcut, unite, quantite, tu_heures, avancement_pct, created_at, updated_at)
            VALUES (:eid, :cid, :nom, :shortcut, :unite, :qte, :tuh, 0, NOW(), NOW())';
    $st = $pdo->prepare($sql);
    $ok = $st->execute([
      ':eid'      => $entrepriseId,
      ':cid'      => $chantierId,
      ':nom'      => $nom,
      ':shortcut' => ($shortcut !== '' ? $shortcut : null),
      ':unite'    => $unite,
      ':qte'      => $quantite,
      ':tuh'      => $tuH,
    ]);
    $newId = $ok ? (int)$pdo->lastInsertId() : 0;
    jexit(200, ['success' => (bool)$ok, 'id' => $newId]);
  } else {
    $sql = 'UPDATE chantier_taches
            SET nom = :nom,
                shortcut = :shortcut,
                unite = :unite,
                quantite = :qte,
                tu_heures = :tuh,
                updated_at = NOW()
            WHERE id = :tid AND chantier_id = :cid AND entreprise_id = :eid
            LIMIT 1';
    $st = $pdo->prepare($sql);
    $ok = $st->execute([
      ':nom'      => $nom,
      ':shortcut' => ($shortcut !== '' ? $shortcut : null),
      ':unite'    => $unite,
      ':qte'      => $quantite,
      ':tuh'      => $tuH,
      ':tid'      => $tacheId,
      ':cid'      => $chantierId,
      ':eid'      => $entrepriseId,
    ]);
    jexit(200, ['success' => (bool)$ok]);
  }
} catch (Throwable $e) {
  jexit(500, ['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()]);
}
