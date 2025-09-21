<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
while (ob_get_level()) ob_end_clean();
ob_start();

require_once dirname(__DIR__, 2) . '/config/init.php';

$out = ['success' => false, 'debug' => []];

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Méthode invalide');
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $entrepriseId  = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);
    $utilisateurId = (int)($input['utilisateur_id'] ?? 0);
    $chantierId    = (int)($input['chantier_id'] ?? 0);
    $tacheId       = (int)($input['tache_id'] ?? 0);
    $dateJour      = (string)($input['date_jour'] ?? '');
    $heures        = (float)($input['heures'] ?? 0.0);

    // Normalisation date
    $dt = DateTime::createFromFormat('Y-m-d', $dateJour);
    if ($dt === false) throw new Exception('Date invalide');
    $dateJour = $dt->format('Y-m-d');

    $out['debug']['payload'] = compact('entrepriseId','utilisateurId','chantierId','tacheId','dateJour','heures');

    if (!$entrepriseId || !$utilisateurId || !$chantierId || !$dateJour) {
        throw new Exception('Paramètres incomplets');
    }

    // Vérif utilisateur
    $chk = $pdo->prepare("SELECT 1 FROM utilisateurs WHERE id = ? AND entreprise_id = ?");
    $chk->execute([$utilisateurId, $entrepriseId]);
    if (!$chk->fetchColumn()) throw new Exception('Utilisateur non autorisé');

    // === Désélection : on supprime la ligne de pointages_taches et on vide tache_id côté jour ===
    if ($tacheId === 0) {
        $out['debug']['branch'] = 'delete';

        $del = $pdo->prepare("
            DELETE FROM pointages_taches
            WHERE entreprise_id = :eid
              AND utilisateur_id = :uid
              AND date_jour = :d
        ");
        $del->execute([':eid'=>$entrepriseId, ':uid'=>$utilisateurId, ':d'=>$dateJour]);
        $out['debug']['deleted_rows'] = $del->rowCount();

        // garder les heures dans pointages_jour, juste vider tache_id
        $upPj = $pdo->prepare("
            UPDATE pointages_jour
               SET tache_id = NULL, updated_at = NOW()
             WHERE entreprise_id = :eid
               AND utilisateur_id = :uid
               AND date_jour = :d
        ");
        $upPj->execute([':eid'=>$entrepriseId, ':uid'=>$utilisateurId, ':d'=>$dateJour]);

        // état après
        $sel = $pdo->prepare("
            SELECT * FROM pointages_taches
            WHERE entreprise_id = ? AND utilisateur_id = ? AND date_jour = ?
            ORDER BY id DESC LIMIT 1
        ");
        $sel->execute([$entrepriseId, $utilisateurId, $dateJour]);
        $out['debug']['after_select'] = $sel->fetch(PDO::FETCH_ASSOC);

        $out['success'] = true;
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // === Sélection / UPSERT ===
    $out['debug']['branch'] = 'upsert';

    $ins = $pdo->prepare("
        INSERT INTO pointages_taches
            (entreprise_id, utilisateur_id, chantier_id, date_jour, tache_id, heures, updated_at)
        VALUES
            (:eid, :uid, :cid, :d, :tid, :h, NOW())
        ON DUPLICATE KEY UPDATE
            tache_id   = VALUES(tache_id),
            heures     = VALUES(heures),
            chantier_id= VALUES(chantier_id),
            updated_at = NOW()
    ");
    $ins->execute([
        ':eid'=>$entrepriseId, ':uid'=>$utilisateurId, ':cid'=>$chantierId,
        ':d'=>$dateJour, ':tid'=>$tacheId, ':h'=>$heures
    ]);
    $out['debug']['rowCount'] = $ins->rowCount();
    $out['debug']['lastInsertId'] = $pdo->lastInsertId();

    // synchro vers pointages_jour (clé unique eid+uid+date)
    $pj = $pdo->prepare("
        INSERT INTO pointages_jour
            (entreprise_id, utilisateur_id, date_jour, chantier_id, tache_id, heures, updated_at)
        VALUES
            (:eid, :uid, :d, :cid, :tid, :h, NOW())
        ON DUPLICATE KEY UPDATE
            chantier_id = VALUES(chantier_id),
            tache_id    = VALUES(tache_id),
            heures      = VALUES(heures),
            updated_at  = NOW()
    ");
    $pj->execute([
        ':eid'=>$entrepriseId, ':uid'=>$utilisateurId, ':d'=>$dateJour,
        ':cid'=>$chantierId, ':tid'=>$tacheId, ':h'=>$heures
    ]);

    // relire ce qui a été écrit
    $sel = $pdo->prepare("
        SELECT * FROM pointages_taches
        WHERE entreprise_id = ? AND utilisateur_id = ? AND date_jour = ?
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    ");
    $sel->execute([$entrepriseId, $utilisateurId, $dateJour]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    $out['debug']['after_select'] = $row;

    // libellé pour le front (shortcut prioritaire)
    $cols = $pdo->query("SHOW COLUMNS FROM chantier_taches")->fetchAll(PDO::FETCH_COLUMN, 0);
    $labelCol = null;
    foreach (['libelle','nom','titre','intitule','label'] as $c) {
        if (in_array($c, $cols, true)) { $labelCol = $c; break; }
    }
    $libExpr = "COALESCE(NULLIF(`shortcut`, ''), " . ($labelCol ? "NULLIF(`$labelCol`, '')," : "") . " CAST(`id` AS CHAR))";
    $s2  = $pdo->prepare("SELECT $libExpr AS lib FROM chantier_taches WHERE id = ? AND entreprise_id = ?");
    $s2->execute([$tacheId, $entrepriseId]);
    $lib = (string)($s2->fetchColumn() ?: '');

    $out['success'] = true;
    $out['tache'] = ['libelle' => $lib];
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    $out['success'] = false;
    $out['debug']['exception'] = $e->getMessage();
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}
