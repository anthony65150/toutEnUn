<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/init.php';
header('Content-Type: application/json; charset=utf-8');

try {
    // Auth : admin uniquement
    if (
        !isset($_SESSION['utilisateurs']) ||
        (($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur')
    ) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Non autorisé']);
        exit;
    }

    // Contexte
    $entrepriseId = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);
    $empId        = (int)($_POST['emp_id'] ?? 0);
    $date         = $_POST['date'] ?? null;

    if ($entrepriseId <= 0 || $empId <= 0 || !$date) {
        throw new Exception('Paramètres manquants ou invalides');
    }

    // Validation format date
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        throw new Exception('Date invalide (format attendu YYYY-MM-DD)');
    }

    // Suppression physique de la ligne => plus de souci de FK
    $st = $pdo->prepare("
        DELETE FROM planning_affectations
        WHERE entreprise_id  = :e
          AND utilisateur_id = :u
          AND date_jour      = :d
        LIMIT 1
    ");
    $st->execute([
        ':e' => $entrepriseId,
        ':u' => $empId,
        ':d' => $date
    ]);

    echo json_encode(['ok' => true, 'date' => $date]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
