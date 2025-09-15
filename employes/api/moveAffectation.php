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
    $chantierId = isset($_POST['chantier_id']) ? (int)$_POST['chantier_id'] : null;
    $depotId    = isset($_POST['depot_id'])    ? (int)$_POST['depot_id']    : null;
    $date       = $_POST['date'] ?? null;

    if ($empId <= 0 || !$date) {
        throw new Exception("Paramètres manquants (emp_id, date)");
    }

    // Validation date ISO
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        throw new Exception("Date invalide (format attendu YYYY-MM-DD)");
    }

    // Vérif colonnes
    $hasTypeCol  = false;
    $hasDepotCol = false;
    try {
        $rs = $pdo->query("SHOW COLUMNS FROM planning_affectations LIKE 'type'");
        $hasTypeCol = $rs && $rs->rowCount() > 0;
    } catch (Throwable $e) {}
    try {
        $rs = $pdo->query("SHOW COLUMNS FROM planning_affectations LIKE 'depot_id'");
        $hasDepotCol = $rs && $rs->rowCount() > 0;
    } catch (Throwable $e) {}

    // Un seul des deux doit être fourni
    $hasCh  = is_int($chantierId) && $chantierId > 0;
    $hasDep = is_int($depotId)    && $depotId >= 0; // >=0 car générique = 0
    if (($hasCh && $hasDep) || (!$hasCh && !$hasDep)) {
        throw new Exception("Spécifie soit chantier_id, soit depot_id (un seul).");
    }

    /* =======================
       Affectation chantier
    ======================= */
    if ($hasCh) {
        // Vérif chantier
        $q = $pdo->prepare("SELECT nom FROM chantiers WHERE id = ? AND entreprise_id = ?");
        $q->execute([$chantierId, $entrepriseId]);
        $nomChantier = $q->fetchColumn();
        if (!$nomChantier) {
            throw new Exception("Chantier introuvable pour cette entreprise.");
        }

        if ($hasTypeCol && $hasDepotCol) {
            $sql = "
                INSERT INTO planning_affectations (entreprise_id, utilisateur_id, date_jour, type, chantier_id, depot_id, is_active)
                VALUES (:e, :u, :d, 'chantier', :c, NULL, 1)
                ON DUPLICATE KEY UPDATE
                  type='chantier', chantier_id=VALUES(chantier_id), depot_id=NULL, is_active=1
            ";
            $st = $pdo->prepare($sql);
            $st->execute([
                ':e' => $entrepriseId,
                ':u' => $empId,
                ':d' => $date,
                ':c' => $chantierId
            ]);
        } else {
            // Compat
            $sql = "
                INSERT INTO planning_affectations (entreprise_id, utilisateur_id, chantier_id, date_jour, is_active)
                VALUES (:e, :u, :c, :d, 1)
                ON DUPLICATE KEY UPDATE chantier_id=VALUES(chantier_id), is_active=1
            ";
            $st = $pdo->prepare($sql);
            $st->execute([
                ':e' => $entrepriseId,
                ':u' => $empId,
                ':c' => $chantierId,
                ':d' => $date
            ]);
        }

        echo json_encode(['ok' => true, 'type' => 'chantier', 'date' => $date, 'chantier_id' => $chantierId]);
        exit;
    }

    /* =======================
       Affectation dépôt
    ======================= */
    $type   = 'depot';
    $label  = 'Dépôt';
    $depIdToStore = null;

    if ($depotId > 0) {
        // Vérif dépôt réel
        $q = $pdo->prepare("SELECT nom FROM depots WHERE id = ? AND entreprise_id = ?");
        $q->execute([$depotId, $entrepriseId]);
        $nomDepot = $q->fetchColumn();
        if (!$nomDepot) {
            throw new Exception("Dépôt introuvable pour cette entreprise.");
        }
        $label = "Dépôt — " . $nomDepot;
        $depIdToStore = $depotId;
    }

    if ($hasTypeCol && $hasDepotCol) {
        $sql = "
            INSERT INTO planning_affectations (entreprise_id, utilisateur_id, date_jour, type, chantier_id, depot_id, is_active)
            VALUES (:e, :u, :d, 'depot', NULL, :dep, 1)
            ON DUPLICATE KEY UPDATE
              type='depot', chantier_id=NULL, depot_id=VALUES(depot_id), is_active=1
        ";
        $st = $pdo->prepare($sql);
        $st->execute([
            ':e'   => $entrepriseId,
            ':u'   => $empId,
            ':d'   => $date,
            ':dep' => $depIdToStore
        ]);
    } else {
        // Compat : encode dépôt par chantier_id=0
        $sql = "
            INSERT INTO planning_affectations (entreprise_id, utilisateur_id, chantier_id, date_jour, is_active)
            VALUES (:e, :u, 0, :d, 1)
            ON DUPLICATE KEY UPDATE chantier_id=0, is_active=1
        ";
        $st = $pdo->prepare($sql);
        $st->execute([
            ':e' => $entrepriseId,
            ':u' => $empId,
            ':d' => $date
        ]);
    }

    echo json_encode(['ok' => true, 'type' => 'depot', 'date' => $date, 'depot_id' => $depIdToStore ?? 0, 'label' => $label]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
