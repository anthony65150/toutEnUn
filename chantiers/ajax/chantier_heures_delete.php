<?php
// chantiers/ajax/chantier_heures_delete.php
declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/init.php';

try {
    if (!isset($_SESSION['utilisateurs'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Non authentifié']);
        exit;
    }
    $user         = $_SESSION['utilisateurs'];
    $role         = (string)($user['fonction'] ?? '');
    $entrepriseId = (int)($user['entreprise_id'] ?? 0);
    if (!$entrepriseId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Entreprise non définie']);
        exit;
    }
    // Autorise seulement admin (change ici si tu veux autoriser chef)
    if ($role !== 'administrateur') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Droits insuffisants (admin requis)']);
        exit;
    }

    // POST JSON
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true) ?? $_POST;

    $csrf = (string)($data['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'CSRF invalide']);
        exit;
    }

    $tacheId    = (int)($data['tache_id'] ?? 0);
    $chantierId = (int)($data['chantier_id'] ?? 0);
    if ($tacheId <= 0 || $chantierId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Paramètres manquants']);
        exit;
    }

    // Vérif appartenance
    $st = $pdo->prepare("
        SELECT id FROM chantier_taches
        WHERE id = :tid AND chantier_id = :cid AND entreprise_id = :eid
        LIMIT 1
    ");
    $st->execute([':tid' => $tacheId, ':cid' => $chantierId, ':eid' => $entrepriseId]);
    if (!$st->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Tâche introuvable pour ce chantier/entreprise']);
        exit;
    }

    // Suppression
    $del = $pdo->prepare("
        DELETE FROM chantier_taches
        WHERE id = :tid AND chantier_id = :cid AND entreprise_id = :eid
        LIMIT 1
    ");
    $del->execute([':tid' => $tacheId, ':cid' => $chantierId, ':eid' => $entrepriseId]);

    if ($del->rowCount() < 1) {
        // FK RESTRICT ou autre ? On le dit clairement.
        echo json_encode([
            'success' => false,
            'message' => 'Suppression non effectuée (rowCount=0). Vérifie les contraintes de clé étrangère.'
        ]);
        exit;
    }

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erreur SQL',
        'sqlstate' => $e->getCode(),
        'detail' => $e->getMessage()
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur serveur', 'detail' => $e->getMessage()]);
}
