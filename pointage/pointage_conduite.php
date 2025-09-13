<?php
// /pointage/pointage_conduite.php
declare(strict_types=1);
ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_SESSION['utilisateurs'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Accès refusé']);
        exit;
    }

    $user         = $_SESSION['utilisateurs'];
    $entrepriseId = (int)($user['entreprise_id'] ?? 0);
    $role         = $user['fonction'] ?? '';
    $uidParam     = (int)($_POST['utilisateur_id'] ?? 0);
    $date         = $_POST['date_pointage'] ?? '';
    $type         = strtoupper($_POST['type'] ?? ''); // 'A' ou 'R'
    $chantierId   = (int)($_POST['chantier_id'] ?? 0);
    $remove       = isset($_POST['remove']) ? (bool)$_POST['remove'] : false; // ← nouveau

    // Seuls les admins peuvent pointer pour un autre utilisateur
    $uid = ($role === 'administrateur' && $uidParam > 0) ? $uidParam : (int)($user['id'] ?? 0);

    // validations
    if (!$entrepriseId || !$uid || $chantierId <= 0
        || !in_array($type, ['A','R'], true)
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Paramètres invalides']);
        exit;
    }

    // Table conduite
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
        UNIQUE KEY uq_unique (entreprise_id, utilisateur_id, date_pointage, type)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Table camions (capacité)
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS pointages_camions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entreprise_id INT NOT NULL,
        chantier_id INT NOT NULL,
        date_jour DATE NOT NULL,
        nb_camions INT NOT NULL DEFAULT 1,
        UNIQUE KEY uq (entreprise_id, chantier_id, date_jour)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Cas décochage → suppression
    if ($remove) {
        $del = $pdo->prepare("
          DELETE FROM pointages_conduite
          WHERE entreprise_id=:e AND utilisateur_id=:u AND chantier_id=:c AND date_pointage=:d AND type=:t
        ");
        $del->execute([':e'=>$entrepriseId, ':u'=>$uid, ':c'=>$chantierId, ':d'=>$date, ':t'=>$type]);

        echo json_encode(['success' => true, 'removed' => true]);
        exit;
    }

    // Capacité camions
    $capStmt = $pdo->prepare("
      SELECT nb_camions FROM pointages_camions
      WHERE entreprise_id=? AND chantier_id=? AND date_jour=?
    ");
    $capStmt->execute([$entrepriseId, $chantierId, $date]);
    $cap = (int)($capStmt->fetchColumn() ?: 1);

    // Nombre actuel
    $countStmt = $pdo->prepare("
      SELECT COUNT(*) FROM pointages_conduite
      WHERE entreprise_id=? AND chantier_id=? AND date_pointage=? AND type=?
    ");
    $countStmt->execute([$entrepriseId, $chantierId, $date, $type]);
    $currentCount = (int)$countStmt->fetchColumn();

    // Vérif capacité si >1
    if ($cap > 1) {
        $hasStmt = $pdo->prepare("
          SELECT 1 FROM pointages_conduite
          WHERE entreprise_id=? AND chantier_id=? AND date_pointage=? AND type=? AND utilisateur_id=?
        ");
        $hasStmt->execute([$entrepriseId, $chantierId, $date, $type, $uid]);
        $alreadyHas = (bool)$hasStmt->fetchColumn();
        if (!$alreadyHas && $currentCount >= $cap) {
            echo json_encode(['success'=>false, 'message'=>'Capacité de camions atteinte pour ce jour.']);
            exit;
        }
    } else {
        // Capacité = 1 : on supprime les autres du même type
        $pdo->prepare("
          DELETE FROM pointages_conduite
          WHERE entreprise_id=? AND chantier_id=? AND date_pointage=? AND type=? AND utilisateur_id<>?
        ")->execute([$entrepriseId, $chantierId, $date, $type, $uid]);
    }

    // UPSERT
    $stmt = $pdo->prepare("
      INSERT INTO pointages_conduite (entreprise_id, utilisateur_id, chantier_id, date_pointage, type, updated_at)
      VALUES (:e, :u, :c, :d, :t, NOW())
      ON DUPLICATE KEY UPDATE chantier_id=VALUES(chantier_id), updated_at=NOW()
    ");
    $stmt->execute([':e'=>$entrepriseId, ':u'=>$uid, ':c'=>$chantierId, ':d'=>$date, ':t'=>$type]);

    echo json_encode(['success' => true, 'cap' => $cap]);

} catch (Throwable $e) {
  error_log(basename(__FILE__) . ': ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
}

