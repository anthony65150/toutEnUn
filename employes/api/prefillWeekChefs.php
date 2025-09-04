<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/init.php';
header('Content-Type: application/json; charset=utf-8');

try {
    /* Auth */
    if (
        !isset($_SESSION['utilisateurs']) ||
        (($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur')
    ) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Non autorisé']);
        exit;
    }

    /* Entreprise */
    $entrepriseId = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);
    if ($entrepriseId <= 0) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Entreprise introuvable en session']);
        exit;
    }

    /* Paramètres */
    $startRaw = $_POST['start'] ?? null;
    if (!$startRaw) {
        throw new Exception("Paramètre 'start' manquant.");
    }
    $dt = new DateTime($startRaw);
    // force le lundi de la semaine
    if ($dt->format('N') !== '1') {
        $dt->modify('last monday');
    }
    $monday = $dt->format('Y-m-d');

    /** @var PDO $pdo */

    /* Combien de responsables (utilisateurs) sur des chantiers de l'entreprise ? */
    $sqlCount = "
        SELECT COUNT(*) 
        FROM chantiers c
        JOIN utilisateurs u ON u.id = c.responsable_id
        WHERE c.responsable_id IS NOT NULL
          AND c.entreprise_id = :eid
          AND u.entreprise_id = :eid
    ";
    $stc = $pdo->prepare($sqlCount);
    $stc->execute([':eid' => $entrepriseId]);
    $nbResp = (int)$stc->fetchColumn();
    if ($nbResp === 0) {
        echo json_encode([
            'ok' => true,
            'inserted' => 0,
            'skipped' => 0,
            'hint' => "Aucun responsable défini pour des chantiers de cette entreprise."
        ]);
        exit;
    }

    /* Pré-remplissage lun→ven
       - on choisit un chantier “préféré” par responsable: MIN(c.id)
       - on insère seulement si aucune affectation n’existe déjà pour (entreprise, user, jour)
    */
    $sql = "
        INSERT INTO planning_affectations (entreprise_id, utilisateur_id, chantier_id, date_jour)
        SELECT
            :eid AS entreprise_id,
            cc.responsable_id,
            cc.pref_ch_id,
            d.jour
        FROM (
            SELECT c.responsable_id, MIN(c.id) AS pref_ch_id
            FROM chantiers c
            JOIN utilisateurs u ON u.id = c.responsable_id
            WHERE c.responsable_id IS NOT NULL
              AND c.entreprise_id = :eid
              AND u.entreprise_id = :eid
            GROUP BY c.responsable_id
        ) cc
        JOIN (
            SELECT DATE(:monday) AS jour
            UNION ALL SELECT DATE_ADD(DATE(:monday), INTERVAL 1 DAY)
            UNION ALL SELECT DATE_ADD(DATE(:monday), INTERVAL 2 DAY)
            UNION ALL SELECT DATE_ADD(DATE(:monday), INTERVAL 3 DAY)
            UNION ALL SELECT DATE_ADD(DATE(:monday), INTERVAL 4 DAY)
        ) d
        WHERE NOT EXISTS (
            SELECT 1 FROM planning_affectations pa
            WHERE pa.entreprise_id  = :eid
              AND pa.utilisateur_id = cc.responsable_id
              AND pa.date_jour      = d.jour
        )
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':eid' => $entrepriseId, ':monday' => $monday]);

    $inserted = $st->rowCount(); // nombre d’insertions réalisées (selon driver)
    $target   = $nbResp * 5;     // 5 jours ouvrés

    echo json_encode([
        'ok'       => true,
        'inserted' => $inserted,
        'skipped'  => max(0, $target - $inserted),
        'hint'     => ($inserted === 0 ? "Aucune insertion (déjà rempli ou responsables non définis)." : null),
        'week'     => $monday
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
