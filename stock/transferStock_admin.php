<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';
header('Content-Type: application/json');

/* Sécurité : admin uniquement */
if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? null) !== 'administrateur') {
    echo json_encode(["success" => false, "message" => "Accès refusé."]);
    exit;
}

/* Multi-entreprise */
$ENT_ID = $_SESSION['utilisateurs']['entreprise_id'] ?? null;
$ENT_ID = is_numeric($ENT_ID) ? (int)$ENT_ID : null;

function belongs_or_fallback(PDO $pdo, string $table, int $id, ?int $ENT_ID): bool {
    if ($ENT_ID === null) return true;
    try {
        $st = $pdo->prepare("SELECT 1 FROM {$table} t WHERE t.id = :id AND t.entreprise_id = :eid");
        $st->execute([':id'=>$id, ':eid'=>$ENT_ID]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return true; }
}

/* Méthode / Inputs JSON */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode(["success" => false, "message" => "Méthode non autorisée"]);
    exit;
}

$data = json_decode(file_get_contents("php://input") ?: '[]', true);

$stockId         = (int)($data['stockId'] ?? 0);
$sourceType      = $data['sourceType'] ?? null;         // 'depot' | 'chantier'
$sourceId        = (int)($data['sourceId'] ?? 0);
$destinationType = $data['destinationType'] ?? null;    // 'depot' | 'chantier'
$destinationId   = (int)($data['destinationId'] ?? 0);
$qty             = (int)($data['qty'] ?? 0);

$adminId = (int)($_SESSION['utilisateurs']['id'] ?? 0);
$allowedTypes = ['depot', 'chantier'];

/* Validations rapides */
if ($stockId <= 0 || $qty <= 0 || !$sourceType || !$destinationType || !$adminId) {
    echo json_encode(["success" => false, "message" => "Données invalides."]);
    exit;
}
if (!in_array($sourceType, $allowedTypes, true) || !in_array($destinationType, $allowedTypes, true)) {
    echo json_encode(["success" => false, "message" => "Type source/destination invalide."]);
    exit;
}
if ($sourceId <= 0 || $destinationId <= 0) {
    echo json_encode(["success" => false, "message" => "IDs source/destination invalides."]);
    exit;
}
if ($sourceType === $destinationType && $sourceId === $destinationId) {
    echo json_encode(["success" => false, "message" => "Source et destination identiques."]);
    exit;
}

/* Garde-fous multi-entreprise */
if ($ENT_ID !== null) {
    if (!belongs_or_fallback($pdo, 'stock', $stockId, $ENT_ID)) { echo json_encode(["success"=>false,"message"=>"Article hors de votre entreprise."]); exit; }
    if ($sourceType === 'depot'     && !belongs_or_fallback($pdo, 'depots',     $sourceId, $ENT_ID)) { echo json_encode(["success"=>false,"message"=>"Source (dépôt) hors de votre entreprise."]); exit; }
    if ($sourceType === 'chantier'  && !belongs_or_fallback($pdo, 'chantiers',  $sourceId, $ENT_ID)) { echo json_encode(["success"=>false,"message"=>"Source (chantier) hors de votre entreprise."]); exit; }
    if ($destinationType === 'depot'    && !belongs_or_fallback($pdo, 'depots',    $destinationId, $ENT_ID)) { echo json_encode(["success"=>false,"message"=>"Destination (dépôt) hors de votre entreprise."]); exit; }
    if ($destinationType === 'chantier' && !belongs_or_fallback($pdo, 'chantiers', $destinationId, $ENT_ID)) { echo json_encode(["success"=>false,"message"=>"Destination (chantier) hors de votre entreprise."]); exit; }
}

try {
    $pdo->beginTransaction();

    /* 1) Stock disponible à la source (avec verrou) */
    if ($sourceType === 'depot') {
        $sql = "
            SELECT sd.quantite
            FROM stock_depots sd
            JOIN depots d ON d.id = sd.depot_id
            WHERE sd.stock_id = :sid
              AND sd.depot_id = :did";
        $params = [':sid'=>$stockId, ':did'=>$sourceId];

        if ($ENT_ID !== null) {
            $sqlWithEnt = $sql . " AND d.entreprise_id = :eid FOR UPDATE";
            $paramsEnt = $params; $paramsEnt[':eid'] = $ENT_ID;   // << pas de $params + [...]
            try {
                $stmt = $pdo->prepare($sqlWithEnt);
                $stmt->execute($paramsEnt);
            } catch (Throwable $e) {
                $stmt = $pdo->prepare($sql . " FOR UPDATE");
                $stmt->execute($params);
            }
        } else {
            $stmt = $pdo->prepare($sql . " FOR UPDATE");
            $stmt->execute($params);
        }
        $quantiteSource = (int)($stmt->fetchColumn() ?: 0);

    } else { // chantier
        $sql = "
            SELECT sc.quantite
            FROM stock_chantiers sc
            JOIN chantiers c ON c.id = sc.chantier_id
            WHERE sc.stock_id = :sid
              AND sc.chantier_id = :cid";
        $params = [':sid'=>$stockId, ':cid'=>$sourceId];

        if ($ENT_ID !== null) {
            $sqlWithEnt = $sql . " AND c.entreprise_id = :eid FOR UPDATE";
            $paramsEnt = $params; $paramsEnt[':eid'] = $ENT_ID;   // << idem
            try {
                $stmt = $pdo->prepare($sqlWithEnt);
                $stmt->execute($paramsEnt);
            } catch (Throwable $e) {
                $stmt = $pdo->prepare($sql . " FOR UPDATE");
                $stmt->execute($params);
            }
        } else {
            $stmt = $pdo->prepare($sql . " FOR UPDATE");
            $stmt->execute($params);
        }
        $quantiteSource = (int)($stmt->fetchColumn() ?: 0);
    }

    if ($quantiteSource < $qty) {
        throw new Exception("Stock insuffisant à la source. Disponible : $quantiteSource.");
    }

    /* 2) Réservations en attente à la source (FOR UPDATE en dernier) */
    $base = "
        SELECT COALESCE(SUM(t.quantite), 0)
        FROM transferts_en_attente t
        WHERE t.article_id = :sid
          AND t.source_type = :stype
          AND t.source_id = :sid2
          AND t.statut = 'en_attente'";
    $p = [':sid'=>$stockId, ':stype'=>$sourceType, ':sid2'=>$sourceId];

    if ($ENT_ID !== null) {
        $sqlWithEnt = $base . " AND t.entreprise_id = :eid FOR UPDATE";
        $pEnt = $p; $pEnt[':eid'] = $ENT_ID;                        // << idem
        try {
            $stmt = $pdo->prepare($sqlWithEnt);
            $stmt->execute($pEnt);
        } catch (Throwable $e) {
            $stmt = $pdo->prepare($base . " FOR UPDATE");
            $stmt->execute($p);
        }
    } else {
        $stmt = $pdo->prepare($base . " FOR UPDATE");
        $stmt->execute($p);
    }

    $enAttente = (int)$stmt->fetchColumn();
    $disponibleApresAttente = $quantiteSource - $enAttente;
    if ($disponibleApresAttente < $qty) {
        throw new Exception("Stock insuffisant (après transferts en attente). Disponible : $disponibleApresAttente.");
    }

    /* 3) Insérer le transfert en attente */
    $insertSql = "
        INSERT INTO transferts_en_attente
            (article_id, source_type, source_id, destination_type, destination_id, quantite, demandeur_id, statut"
        . ($ENT_ID !== null ? ", entreprise_id" : "") . ")
        VALUES
            (:a, :st, :sid, :dt, :did, :q, :uid, 'en_attente'"
        . ($ENT_ID !== null ? ", :eid" : "") . ")";
    $paramsIns = [
        ':a'=>$stockId, ':st'=>$sourceType, ':sid'=>$sourceId,
        ':dt'=>$destinationType, ':did'=>$destinationId,
        ':q'=>$qty, ':uid'=>$adminId
    ];
    if ($ENT_ID !== null) { $paramsIns[':eid'] = $ENT_ID; }

    try {
        $stmt = $pdo->prepare($insertSql);
        $stmt->execute($paramsIns);
    } catch (Throwable $e) {
        $stmt = $pdo->prepare("
            INSERT INTO transferts_en_attente
                (article_id, source_type, source_id, destination_type, destination_id, quantite, demandeur_id, statut)
            VALUES
                (:a, :st, :sid, :dt, :did, :q, :uid, 'en_attente')
        ");
        $stmt->execute([
            ':a'=>$stockId, ':st'=>$sourceType, ':sid'=>$sourceId,
            ':dt'=>$destinationType, ':did'=>$destinationId,
            ':q'=>$qty, ':uid'=>$adminId
        ]);
    }

    /* 4) Décrément immédiat si la source est un dépôt */
    if ($sourceType === 'depot') {
        $stmt = $pdo->prepare("
            UPDATE stock_depots
               SET quantite = GREATEST(quantite - :q, 0)
             WHERE stock_id = :sid AND depot_id = :did
        ");
        $stmt->execute([':q'=>$qty, ':sid'=>$stockId, ':did'=>$sourceId]);
    }

    $pdo->commit();
    echo json_encode(["success" => true, "message" => "Transfert enregistré et en attente de validation."]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
