<?php
// /pointage/pointage_camion.php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if (!isset($_SESSION['utilisateurs'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Accès refusé'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $user         = $_SESSION['utilisateurs'];
    $entrepriseId = (int)($user['entreprise_id'] ?? 0);

    // Params (fallback sur "date")
    $action     = $_POST['action']      ?? 'get'; // get | set | inc | dec
    $chantierId = (int)($_POST['chantier_id'] ?? 0);
    $dateJour   = $_POST['date_jour']   ?? ($_POST['date'] ?? '');

    // Validation
    if ($entrepriseId <= 0 || $chantierId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateJour)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Paramètres invalides'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // (Sécurité) s'assurer que le chantier appartient bien à l'entreprise
    $stChk = $pdo->prepare("SELECT 1 FROM chantiers WHERE id = ? AND entreprise_id = ? LIMIT 1");
    $stChk->execute([$chantierId, $entrepriseId]);
    if (!$stChk->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Chantier introuvable'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Table capacités (si tu préfères, mets ça dans une migration)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pointages_camions (
          id INT AUTO_INCREMENT PRIMARY KEY,
          entreprise_id INT NOT NULL,
          chantier_id INT NOT NULL,
          date_jour DATE NOT NULL,
          nb_camions INT NOT NULL DEFAULT 1,
          updated_at DATETIME NULL,
          UNIQUE KEY uq (entreprise_id, chantier_id, date_jour),
          KEY idx_ent_date (entreprise_id, date_jour)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Bornes
    $MIN = 1;
    $MAX = 20;

    $getStmt = $pdo->prepare("
        SELECT nb_camions
        FROM pointages_camions
        WHERE entreprise_id = ? AND chantier_id = ? AND date_jour = ?
    ");

    // ⚠️ VALUES() OK en MySQL ≤8.0.19. Si tu es en 8.0.20+, garde ça
    // tant que ça marche pour toi ; sinon remplace par la variante avec alias:
    //
    // INSERT ... VALUES (...) AS new
    // ON DUPLICATE KEY UPDATE nb_camions = new.nb_camions, updated_at = NOW()
    //
    $setStmt = $pdo->prepare("
        INSERT INTO pointages_camions (entreprise_id, chantier_id, date_jour, nb_camions, updated_at)
        VALUES (:e, :c, :d, :n, NOW())
        ON DUPLICATE KEY UPDATE nb_camions = VALUES(nb_camions), updated_at = NOW()
    ");

    // Valeur actuelle (défaut 1)
    $getStmt->execute([$entrepriseId, $chantierId, $dateJour]);
    $current = (int)($getStmt->fetchColumn() ?: 1);

    // Calcul nouvelle valeur
    $action = strtolower(trim($action));
    $nb = $current;

    switch ($action) {
        case 'get':
            // inchangé
            break;

        case 'inc':
            $nb = min($MAX, max($MIN, $current + 1));
            break;

        case 'dec':
            $nb = min($MAX, max($MIN, $current - 1));
            break;

        case 'set':
            $posted = isset($_POST['nb']) ? (int)$_POST['nb'] : $current;
            $nb = min($MAX, max($MIN, $posted));
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Action invalide'], JSON_UNESCAPED_UNICODE);
            exit;
    }

    // Persist si modifié
    if ($action !== 'get' && $nb !== $current) {
        $setStmt->execute([
            ':e' => $entrepriseId,
            ':c' => $chantierId,
            ':d' => $dateJour,
            ':n' => $nb,
        ]);
    }

    echo json_encode(['success' => true, 'nb' => $nb], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('pointage_camion.php: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur serveur'], JSON_UNESCAPED_UNICODE);
}
