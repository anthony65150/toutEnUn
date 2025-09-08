<?php
// Fichier: /stock/transferStock_chef.php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json; charset=utf-8');

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new Exception("Méthode non autorisée");
    }

    if (empty($_SESSION['utilisateurs'])) {
        throw new Exception("Session invalide.");
    }
    $user   = $_SESSION['utilisateurs'];
    $userId = (int)($user['id'] ?? 0);
    $role   = (string)($user['fonction'] ?? '');
    $ENT_ID = (int)($user['entreprise_id'] ?? 0);
    if (!$userId || !$ENT_ID) {
        throw new Exception("Contexte utilisateur/entreprise manquant.");
    }

    // JSON ou FormData
    $payload = json_decode(file_get_contents("php://input"), true);
    if (!is_array($payload)) $payload = $_POST;

    // Mapping souple
    $stockId         = (int)($payload['stockId'] ?? $payload['article_id'] ?? 0);
    $sourceType      =       ($payload['sourceType'] ?? $payload['source_type'] ?? null);            // 'depot'|'chantier'
    $sourceId        = (int)($payload['sourceId'] ?? $payload['source_id'] ?? 0);
    $destinationType =       ($payload['destinationType'] ?? $payload['destination_type'] ?? null);  // 'depot'|'chantier'
    $destinationId   = (int)($payload['destinationId'] ?? $payload['destination_id'] ?? 0);
    $qty             = (int)($payload['qty'] ?? $payload['quantity'] ?? 0);

    // Validation basique
    $missing = [];
    if (!$stockId)         $missing[] = 'stockId/article_id';
    if (!$qty || $qty < 1) $missing[] = 'qty/quantity';
    if (!$sourceType)      $missing[] = 'sourceType/source_type';
    if ($sourceId <= 0)    $missing[] = 'sourceId/source_id';
    if (!$destinationType) $missing[] = 'destinationType/destination_type';
    if ($destinationId<=0) $missing[] = 'destinationId/destination_id';
    if ($missing) throw new Exception('Données invalides: '.implode(', ', $missing));

    $allowed = ['depot','chantier'];
    if (!in_array($sourceType, $allowed, true) || !in_array($destinationType, $allowed, true)) {
        throw new Exception("Type source/destination invalide.");
    }
    if ($sourceType === $destinationType && $sourceId === $destinationId) {
        throw new Exception("Source et destination identiques.");
    }

    // ---- CONTRÔLES MULTI-ENTREPRISE ----
    // 1) Article de l’entreprise
    $stmt = $pdo->prepare("SELECT 1 FROM stock WHERE id = ? AND entreprise_id = ?");
    $stmt->execute([$stockId, $ENT_ID]);
    if (!$stmt->fetchColumn()) throw new Exception("Article introuvable pour cette entreprise.");

    // 2) Source dans l’entreprise (+ droit du chef)
    if ($sourceType === 'chantier') {
        $stmt = $pdo->prepare("SELECT 1 FROM chantiers WHERE id = ? AND entreprise_id = ?");
        $stmt->execute([$sourceId, $ENT_ID]);
        if (!$stmt->fetchColumn()) throw new Exception("Chantier source non autorisé.");

        if ($role !== 'administrateur') {
            $stmt = $pdo->prepare("SELECT 1 FROM utilisateur_chantiers WHERE utilisateur_id = ? AND chantier_id = ? LIMIT 1");
            $stmt->execute([$userId, $sourceId]);
            if (!$stmt->fetchColumn()) throw new Exception("Vous ne pouvez transférer que depuis vos chantiers.");
        }
    } else { // depot
        if ($role === 'chef') throw new Exception("Un chef ne peut pas initier un transfert depuis un dépôt.");
        $stmt = $pdo->prepare("SELECT 1 FROM depots WHERE id = ? AND entreprise_id = ?");
        $stmt->execute([$sourceId, $ENT_ID]);
        if (!$stmt->fetchColumn()) throw new Exception("Dépôt source non autorisé.");
    }

    // 3) Destination dans l’entreprise
    if ($destinationType === 'chantier') {
        $stmt = $pdo->prepare("SELECT 1 FROM chantiers WHERE id = ? AND entreprise_id = ?");
        $stmt->execute([$destinationId, $ENT_ID]);
    } else {
        $stmt = $pdo->prepare("SELECT 1 FROM depots WHERE id = ? AND entreprise_id = ?");
        $stmt->execute([$destinationId, $ENT_ID]);
    }
    if (!$stmt->fetchColumn()) throw new Exception("Destination non autorisée.");

    // ---- LOGIQUE STOCK + RÉSERVATIONS ----
    $pdo->beginTransaction();

    // Quantité disponible à la source (verrou)
    if ($sourceType === 'depot') {
        $qSrc = $pdo->prepare("SELECT quantite FROM stock_depots WHERE stock_id = ? AND depot_id = ? FOR UPDATE");
    } else {
        $qSrc = $pdo->prepare("SELECT quantite FROM stock_chantiers WHERE stock_id = ? AND chantier_id = ? FOR UPDATE");
    }
    $qSrc->execute([$stockId, $sourceId]);
    $quantiteSource = (int)($qSrc->fetchColumn() ?: 0);

    // Réservations en attente (tente de filtrer par entreprise_id, sinon fallback)
    $sumBase = "
        SELECT COALESCE(SUM(quantite),0)
        FROM transferts_en_attente
        WHERE article_id = :sid
          AND source_type = :stype
          AND source_id   = :sid2
          AND statut      = 'en_attente'
    ";
    $p = [':sid'=>$stockId, ':stype'=>$sourceType, ':sid2'=>$sourceId];

    try {
        $stmt = $pdo->prepare($sumBase . " AND entreprise_id = :eid FOR UPDATE");
        $pEnt = $p; $pEnt[':eid'] = $ENT_ID;
        $stmt->execute($pEnt);
    } catch (Throwable $e) {
        $stmt = $pdo->prepare($sumBase . " FOR UPDATE");
        $stmt->execute($p);
    }
    $enAttente  = (int)$stmt->fetchColumn();
    $disponible = $quantiteSource - $enAttente;
    if ($disponible < $qty) throw new Exception("Stock insuffisant (disponible: {$disponible}).");

    // Enregistrer le transfert en attente (chef : pas de décrément immédiat)
    try {
        $ins = $pdo->prepare("
            INSERT INTO transferts_en_attente
                (article_id, source_type, source_id, destination_type, destination_id, quantite, demandeur_id, statut, entreprise_id, created_at)
            VALUES
                (:sid, :stype, :sid_i, :dtype, :did_i, :qte, :uid, 'en_attente', :eid, NOW())
        ");
        $ins->execute([
            ':sid'   => $stockId,
            ':stype' => $sourceType,
            ':sid_i' => $sourceId,
            ':dtype' => $destinationType,
            ':did_i' => $destinationId,
            ':qte'   => $qty,
            ':uid'   => $userId,
            ':eid'   => $ENT_ID,
        ]);
    } catch (Throwable $e) {
        // Fallback si la colonne entreprise_id n'existe pas
        $ins = $pdo->prepare("
            INSERT INTO transferts_en_attente
                (article_id, source_type, source_id, destination_type, destination_id, quantite, demandeur_id, statut, created_at)
            VALUES
                (:sid, :stype, :sid_i, :dtype, :did_i, :qte, :uid, 'en_attente', NOW())
        ");
        $ins->execute([
            ':sid'   => $stockId,
            ':stype' => $sourceType,
            ':sid_i' => $sourceId,
            ':dtype' => $destinationType,
            ':did_i' => $destinationId,
            ':qte'   => $qty,
            ':uid'   => $userId,
        ]);
    }

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Transfert enregistré et en attente de validation."
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(["success"=>false, "message"=>$e->getMessage()]);
}
