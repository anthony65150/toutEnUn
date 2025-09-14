<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/init.php';
header('Content-Type: application/json; charset=utf-8');

try {
    // Authentification : uniquement administrateur
    if (
        !isset($_SESSION['utilisateurs']) ||
        (($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur')
    ) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Non autorisé']);
        exit;
    }

    // Contexte entreprise
    $entrepriseId = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);
    if ($entrepriseId <= 0) {
        throw new Exception("Entreprise invalide en session");
    }

    // Inputs
    $empId      = (int)($_POST['emp_id'] ?? 0);
    $chantierId = (int)($_POST['chantier_id'] ?? 0);
    $date       = $_POST['date'] ?? null;

    if ($empId <= 0 || $chantierId <= 0 || !$date) {
        throw new Exception("Paramètres manquants ou invalides");
    }

    // Vérif basique du format de la date
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        throw new Exception("Date invalide (format attendu YYYY-MM-DD)");
    }

    // Insertion / mise à jour : on force is_active=1 dès qu’un chantier est affecté
    $sql = "
        INSERT INTO planning_affectations (entreprise_id, utilisateur_id, chantier_id, date_jour, is_active)
        VALUES (:e, :u, :c, :d, 1)
        ON DUPLICATE KEY UPDATE
            chantier_id = VALUES(chantier_id),
            is_active   = 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':e' => $entrepriseId,
        ':u' => $empId,
        ':c' => $chantierId,
        ':d' => $date
    ]);

    echo json_encode(['ok' => true, 'date' => $date, 'chantier_id' => $chantierId]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
