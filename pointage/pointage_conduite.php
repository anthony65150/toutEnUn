<?php
// /pointage/pointage_conduite.php
declare(strict_types=1);

ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json; charset=utf-8');

try {
    // --- Sécurité requête & session
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_SESSION['utilisateurs'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Accès refusé'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $user         = $_SESSION['utilisateurs'];
    $entrepriseId = (int)($user['entreprise_id'] ?? 0);
    $role         = (string)($user['fonction'] ?? '');

    // Params
    $uidParam   = (int)($_POST['utilisateur_id'] ?? 0);
    $date       = (string)($_POST['date_pointage'] ?? '');
    $type       = strtoupper((string)($_POST['type'] ?? '')); // 'A' | 'R'
    $chantierId = (int)($_POST['chantier_id'] ?? 0);
    // 'remove' tolère '1', 'true', 'on', etc.
    $remove     = filter_var($_POST['remove'] ?? '0', FILTER_VALIDATE_BOOLEAN);

    // Seuls les admins peuvent pointer pour un autre utilisateur
    $uid = ($role === 'administrateur' && $uidParam > 0) ? $uidParam : (int)($user['id'] ?? 0);

    // Validations
    if ($entrepriseId <= 0 || $uid <= 0 || $chantierId <= 0
        || !in_array($type, ['A','R'], true)
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Paramètres invalides'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Vérifier que le chantier appartient à l’entreprise
    $stChk = $pdo->prepare("SELECT 1 FROM chantiers WHERE id=? AND entreprise_id=? LIMIT 1");
    $stChk->execute([$chantierId, $entrepriseId]);
    if (!$stChk->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Chantier introuvable'], JSON_UNESCAPED_UNICODE);
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
        UNIQUE KEY uq_unique (entreprise_id, utilisateur_id, date_pointage, type),
        KEY idx_ent_chantier_date_type (entreprise_id, chantier_id, date_pointage, type)
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
        UNIQUE KEY uq (entreprise_id, chantier_id, date_jour),
        KEY idx_ent_date (entreprise_id, date_jour)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    /* ==========================
       Décochage → suppression
    ========================== */
    if ($remove) {
        $del = $pdo->prepare("
          DELETE FROM pointages_conduite
          WHERE entreprise_id=:e AND utilisateur_id=:u AND chantier_id=:c AND date_pointage=:d AND type=:t
        ");
        $del->execute([':e'=>$entrepriseId, ':u'=>$uid, ':c'=>$chantierId, ':d'=>$date, ':t'=>$type]);

        echo json_encode(['success' => true, 'removed' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ==========================
       Capacité camions (A/R)
    ========================== */
    $capStmt = $pdo->prepare("
      SELECT nb_camions
      FROM pointages_camions
      WHERE entreprise_id=? AND chantier_id=? AND date_jour=?
    ");
    $capStmt->execute([$entrepriseId, $chantierId, $date]);
    $cap = (int)($capStmt->fetchColumn() ?: 1);
    if ($cap <= 0) $cap = 1;

    // Nombre actuel pour ce chantier/date/type
    $countStmt = $pdo->prepare("
      SELECT COUNT(*)
      FROM pointages_conduite
      WHERE entreprise_id=? AND chantier_id=? AND date_pointage=? AND type=?
    ");
    $countStmt->execute([$entrepriseId, $chantierId, $date, $type]);
    $currentCount = (int)$countStmt->fetchColumn();

    if ($cap > 1) {
        // L'utilisateur a-t-il déjà une conduite posée (même chantier/date/type) ?
        $hasStmt = $pdo->prepare("
          SELECT 1
          FROM pointages_conduite
          WHERE entreprise_id=? AND chantier_id=? AND date_pointage=? AND type=? AND utilisateur_id=?
          LIMIT 1
        ");
        $hasStmt->execute([$entrepriseId, $chantierId, $date, $type, $uid]);
        $alreadyHas = (bool)$hasStmt->fetchColumn();

        if (!$alreadyHas && $currentCount >= $cap) {
            echo json_encode(['success'=>false, 'message'=>'Capacité de camions atteinte pour ce jour.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        // Capacité = 1 : on supprime les autres en amont (exclusivité)
        $pdo->prepare("
          DELETE FROM pointages_conduite
          WHERE entreprise_id=? AND chantier_id=? AND date_pointage=? AND type=? AND utilisateur_id<>?
        ")->execute([$entrepriseId, $chantierId, $date, $type, $uid]);
    }

    /* ==========================
       UPSERT (un enregistrement/jour/type/utilisateur)
    ========================== */
    // NOTE MySQL ≥ 8.0.20 : si VALUES() pose problème chez toi,
    // remplace la requête par la variante commentée juste en dessous.
    $stmt = $pdo->prepare("
      INSERT INTO pointages_conduite (entreprise_id, utilisateur_id, chantier_id, date_pointage, type, updated_at)
      VALUES (:e, :u, :c, :d, :t, NOW())
      ON DUPLICATE KEY UPDATE chantier_id=VALUES(chantier_id), updated_at=NOW()
    ");
    /*
    -- Variante compatible 8.0.20+
    $stmt = $pdo->prepare("
      INSERT INTO pointages_conduite (entreprise_id, utilisateur_id, chantier_id, date_pointage, type, updated_at)
      VALUES (:e, :u, :c, :d, :t, NOW()) AS new
      ON DUPLICATE KEY UPDATE chantier_id=new.chantier_id, updated_at=NOW()
    ");
    */
    $stmt->execute([':e'=>$entrepriseId, ':u'=>$uid, ':c'=>$chantierId, ':d'=>$date, ':t'=>$type]);

    echo json_encode(['success' => true, 'cap' => $cap], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false, 'message'=>'Erreur serveur'], JSON_UNESCAPED_UNICODE);
}
