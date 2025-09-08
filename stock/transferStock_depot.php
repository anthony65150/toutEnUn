<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json');

/* ─────────────────────────────────────────────
   0) Accès
───────────────────────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode(["success" => false, "message" => "Méthode non autorisée"]); exit;
}
if (!isset($_SESSION['utilisateurs'])) {
    echo json_encode(["success" => false, "message" => "Non authentifié"]); exit;
}

$user      = $_SESSION['utilisateurs'];
$userId    = (int)($user['id'] ?? 0);
$role      = $user['fonction'] ?? '';
$ENT_ID    = isset($user['entreprise_id']) ? (int)$user['entreprise_id'] : null;

if (!$userId || $ENT_ID === null) {
    echo json_encode(["success" => false, "message" => "Contexte entreprise manquant."]); exit;
}

/* ─────────────────────────────────────────────
   1) INPUT: JSON ou POST
───────────────────────────────────────────── */
$raw   = file_get_contents("php://input");
$asJson = json_decode($raw ?: '', true);

$in = function(string $jsonKey, string $postKey = null, $default = null) use ($asJson) {
    if (is_array($asJson) && array_key_exists($jsonKey, $asJson)) return $asJson[$jsonKey];
    if ($postKey !== null && isset($_POST[$postKey])) return $_POST[$postKey];
    return $default;
};

// Support "destinationChantier" = "depot_2" | "chantier_5"
$destCombo = $in('destination', 'destination', null);
if (!$destCombo && isset($_POST['destinationChantier'])) $destCombo = $_POST['destinationChantier'];
if ($destCombo && !isset($_POST['destination_type']) && !isset($asJson['destinationType'])) {
    if (preg_match('/^(depot|chantier)_(\d+)$/', (string)$destCombo, $m)) {
        $_POST['destination_type'] = $m[1];
        $_POST['destination_id']   = $m[2];
    }
}

$stockId         = (int) ($in('stockId', 'article_id', 0));
$sourceType      =       $in('sourceType', 'source_type', null);
$sourceDepotId   = (int) ($in('sourceId', 'source_depot_id', 0)); // cas dépôt
$destinationType =       $in('destinationType', 'destination_type', null);
$destinationId   = (int) ($in('destinationId', 'destination_id', 0));
$qty             = (int) ($in('qty', 'quantity', 0));

// Si on a un source_depot_id sans type
if (!$sourceType && $sourceDepotId) $sourceType = 'depot';
$sourceId = $sourceDepotId ?: (int) ($in('sourceId', 'source_id', 0));


$allowedTypes = ['depot','chantier'];

/* ─────────────────────────────────────────────
   2) VALIDATIONS RAPIDES
───────────────────────────────────────────── */
if ($stockId <= 0 || $qty <= 0 || !$sourceType || !$destinationType || $sourceId <= 0 || $destinationId <= 0) {
    echo json_encode(["success" => false, "message" => "Données invalides."]); exit;
}
if (!in_array($sourceType, $allowedTypes, true) || !in_array($destinationType, $allowedTypes, true)) {
    echo json_encode(["success" => false, "message" => "Type source/destination invalide."]); exit;
}
if ($sourceType === $destinationType && $sourceId === $destinationId) {
    echo json_encode(["success" => false, "message" => "Source et destination identiques."]); exit;
}

/* ─────────────────────────────────────────────
   3) GARANTIES MULTI-ENTREPRISE
      - l’article appartient à l’entreprise
      - la source et la destination appartiennent à l’entreprise
      - si rôle = 'depot' : le user transfère depuis SON dépôt
───────────────────────────────────────────── */
try {
    // Article → entreprise
    $st = $pdo->prepare("SELECT 1 FROM stock s WHERE s.id = :sid AND s.entreprise_id = :eid");
    $st->execute([':sid'=>$stockId, ':eid'=>$ENT_ID]);
    if (!$st->fetchColumn()) throw new Exception("Article invalide pour cette entreprise.");

    // Source → entreprise (et contrôle propriétaire si rôle dépôt)
    if ($sourceType === 'depot') {
        $st = $pdo->prepare("SELECT d.id, d.responsable_id FROM depots d WHERE d.id = :id AND d.entreprise_id = :eid");
        $st->execute([':id'=>$sourceId, ':eid'=>$ENT_ID]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception("Dépôt source invalide.");
        if ($role === 'depot' && (int)($row['responsable_id'] ?? 0) !== $userId) {
            throw new Exception("Non autorisé à transférer depuis ce dépôt.");
        }
    } else {
        $st = $pdo->prepare("SELECT 1 FROM chantiers c WHERE c.id = :id AND c.entreprise_id = :eid");
        $st->execute([':id'=>$sourceId, ':eid'=>$ENT_ID]);
        if (!$st->fetchColumn()) throw new Exception("Chantier source invalide.");
    }

    // Destination → entreprise
    if ($destinationType === 'depot') {
        $st = $pdo->prepare("SELECT 1 FROM depots d WHERE d.id = :id AND d.entreprise_id = :eid");
        $st->execute([':id'=>$destinationId, ':eid'=>$ENT_ID]);
        if (!$st->fetchColumn()) throw new Exception("Dépôt destination invalide.");
    } else {
        $st = $pdo->prepare("SELECT 1 FROM chantiers c WHERE c.id = :id AND c.entreprise_id = :eid");
        $st->execute([':id'=>$destinationId, ':eid'=>$ENT_ID]);
        if (!$st->fetchColumn()) throw new Exception("Chantier destination invalide.");
    }

    /* ─────────────────────────────────────────
       4) TRANSACTION + LOCK
    ────────────────────────────────────────── */
    $pdo->beginTransaction();

    // 4.1 Stock à la source (FOR UPDATE)
    if ($sourceType === 'depot') {
        $st = $pdo->prepare("
            SELECT sd.quantite
            FROM stock_depots sd
            JOIN depots d ON d.id = sd.depot_id
            WHERE sd.stock_id = :sid AND sd.depot_id = :did AND d.entreprise_id = :eid
            FOR UPDATE
        ");
        $st->execute([':sid'=>$stockId, ':did'=>$sourceId, ':eid'=>$ENT_ID]);
    } else {
        $st = $pdo->prepare("
            SELECT sc.quantite
            FROM stock_chantiers sc
            JOIN chantiers c ON c.id = sc.chantier_id
            WHERE sc.stock_id = :sid AND sc.chantier_id = :cid AND c.entreprise_id = :eid
            FOR UPDATE
        ");
        $st->execute([':sid'=>$stockId, ':cid'=>$sourceId, ':eid'=>$ENT_ID]);
    }
    $quantiteSource = (int)($st->fetchColumn() ?: 0);

    // 4.2 Transferts déjà en attente depuis cette source (FOR UPDATE)
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(t.quantite),0)
        FROM transferts_en_attente t
        JOIN stock s ON s.id = t.article_id
        WHERE t.article_id = :sid
          AND t.source_type = :stype
          AND t.source_id   = :sid2
          AND t.statut = 'en_attente'
          AND s.entreprise_id = :eid
        FOR UPDATE
    ");
    $st->execute([
        ':sid'   => $stockId,
        ':stype' => $sourceType,
        ':sid2'  => $sourceId,
        ':eid'   => $ENT_ID
    ]);
    $enAttente = (int)$st->fetchColumn();

    $dispoApres = $quantiteSource - $enAttente;
    if ($dispoApres < $qty) {
        throw new Exception("Stock insuffisant (après transferts en attente). Disponible : $dispoApres.");
    }

// 4.3 Insérer le transfert en attente (avec entreprise_id si la colonne existe)
try {
    $st = $pdo->prepare("
        INSERT INTO transferts_en_attente
            (article_id, source_type, source_id, destination_type, destination_id, quantite, demandeur_id, statut, entreprise_id, created_at)
        VALUES
            (:sid, :stype, :sid2, :dtype, :did2, :qte, :uid, 'en_attente', :eid, NOW())
    ");
    $st->execute([
        ':sid'   => $stockId,
        ':stype' => $sourceType,
        ':sid2'  => $sourceId,
        ':dtype' => $destinationType,
        ':did2'  => $destinationId,
        ':qte'   => $qty,
        ':uid'   => $userId,
        ':eid'   => $ENT_ID,
    ]);
} catch (Throwable $e) {
    // Fallback si la colonne entreprise_id n'existe pas
    $st = $pdo->prepare("
        INSERT INTO transferts_en_attente
            (article_id, source_type, source_id, destination_type, destination_id, quantite, demandeur_id, statut, created_at)
        VALUES
            (:sid, :stype, :sid2, :dtype, :did2, :qte, :uid, 'en_attente', NOW())
    ");
    $st->execute([
        ':sid'   => $stockId,
        ':stype' => $sourceType,
        ':sid2'  => $sourceId,
        ':dtype' => $destinationType,
        ':did2'  => $destinationId,
        ':qte'   => $qty,
        ':uid'   => $userId
    ]);
}


    // 4.4 Décrément immédiat si source = dépôt
    $newDepotQty = null;
    if ($sourceType === 'depot') {
        $st = $pdo->prepare("
            UPDATE stock_depots sd
            JOIN depots d ON d.id = sd.depot_id
               SET sd.quantite = GREATEST(sd.quantite - :qte, 0)
             WHERE sd.stock_id = :sid AND sd.depot_id = :did AND d.entreprise_id = :eid
        ");
        $st->execute([':qte'=>$qty, ':sid'=>$stockId, ':did'=>$sourceId, ':eid'=>$ENT_ID]);

        $st = $pdo->prepare("
            SELECT sd.quantite
            FROM stock_depots sd
            JOIN depots d ON d.id = sd.depot_id
            WHERE sd.stock_id = :sid AND sd.depot_id = :did AND d.entreprise_id = :eid
        ");
        $st->execute([':sid'=>$stockId, ':did'=>$sourceId, ':eid'=>$ENT_ID]);
        $newDepotQty = (int)$st->fetchColumn();
    }

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Transfert enregistré et en attente de validation.",
        "new_quantite_depot" => $newDepotQty
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
