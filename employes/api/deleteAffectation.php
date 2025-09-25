<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/init.php';
header('Content-Type: application/json; charset=utf-8');

try {
    // ===== Auth : admin uniquement =====
    if (
        !isset($_SESSION['utilisateurs']) ||
        (($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur')
    ) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Non autorisé']);
        exit;
    }

    // ===== Contexte entreprise =====
    $entrepriseId = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);
    if ($entrepriseId <= 0) {
        throw new Exception('Entreprise invalide en session');
    }

    // ===== Inputs =====
    $empId = (int)($_POST['emp_id'] ?? 0);
    $date  = $_POST['date'] ?? null;

    if ($empId <= 0 || !$date) {
        throw new Exception('Paramètres manquants (emp_id, date)');
    }

    // Date ISO stricte
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        throw new Exception('Date invalide (format attendu YYYY-MM-DD)');
    }

    // (Optionnel) CSRF si fourni par le front ; sinon on n’échoue pas
    if (!empty($_POST['csrf'])) {
        $csrf = (string)$_POST['csrf'];
        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
            throw new Exception('CSRF invalide');
        }
    }

    // ===== Suppression idempotente =====
    // On supprime la ligne quel que soit le type (chantier, depot, conges, maladie, rtt).
    // NB: prévoir un index/unique key sur (entreprise_id, utilisateur_id, date_jour).
    $pdo->beginTransaction();

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

    // Idempotent : on renvoie ok=true même si 0 ligne supprimée
    $deleted = $st->rowCount();
    $pdo->commit();

    echo json_encode([
        'ok'      => true,
        'date'    => $date,
        'deleted' => (int)$deleted
    ]);
} catch (Throwable $e) {
    if ($pdo?->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
