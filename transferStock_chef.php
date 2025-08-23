<?php
require_once "./config/init.php";
header('Content-Type: application/json');

// (au cas où) remonter les erreurs PDO clairement
if (isset($pdo)) { $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Méthode non autorisée");
    }

    // Accepter JSON ou FormData
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) { $data = $_POST; }

    // Mapper JSON vs FormData
    $stockId         = (int)($data['stockId'] ?? $data['article_id'] ?? 0);
    $sourceType      =       ($data['sourceType'] ?? $data['source_type'] ?? null);           // 'depot'|'chantier'
    $sourceId        = (int)($data['sourceId'] ?? $data['source_id'] ?? 0);
    $destinationType =       ($data['destinationType'] ?? $data['destination_type'] ?? null); // 'depot'|'chantier'
    $destinationId   = (int)($data['destinationId'] ?? $data['destination_id'] ?? 0);
    $qty             = (int)($data['qty'] ?? $data['quantity'] ?? 0);

    if (empty($_SESSION['utilisateurs'])) {
        throw new Exception("Session invalide.");
    }
    $userId = (int)($_SESSION['utilisateurs']['id'] ?? 0);
    $role   = $_SESSION['utilisateurs']['fonction'] ?? null;

    // === Validation détaillée pour savoir ce qui manque ===
    $missing = [];
    if (!$userId)          $missing[] = 'userId';
    if (!$stockId)         $missing[] = 'stockId/article_id';
    if (!$qty || $qty < 1) $missing[] = 'qty/quantity';
    if (!$sourceType)      $missing[] = 'sourceType/source_type';
    if ($sourceId <= 0)    $missing[] = 'sourceId/source_id';
    if (!$destinationType) $missing[] = 'destinationType/destination_type';
    if ($destinationId<=0) $missing[] = 'destinationId/destination_id';

    if ($missing) {
        throw new Exception('Données invalides: ' . implode(', ', $missing));
    }

    $allowedTypes = ['depot','chantier'];
    if (!in_array($sourceType, $allowedTypes, true) || !in_array($destinationType, $allowedTypes, true)) {
        throw new Exception("Type source/destination invalide.");
    }
    if ($sourceType === $destinationType && $sourceId === $destinationId) {
        throw new Exception("Source et destination identiques.");
    }

    // 🔒 Autorisations : admin passe, sinon le chef doit être assigné si SOURCE=chantier
    if ($role !== 'administrateur' && $sourceType === 'chantier') {
        $stmtChef = $pdo->prepare("
            SELECT 1 FROM utilisateur_chantiers 
            WHERE utilisateur_id = ? AND chantier_id = ? LIMIT 1
        ");
        $stmtChef->execute([$userId, $sourceId]);
        if (!$stmtChef->fetchColumn()) {
            throw new Exception("Vous ne pouvez transférer que depuis vos propres chantiers.");
        }
    }

    $pdo->beginTransaction();

    // Quantité à la source
    if ($sourceType === 'depot') {
        $stmt = $pdo->prepare("SELECT quantite FROM stock_depots WHERE stock_id = ? AND depot_id = ?");
    } else {
        $stmt = $pdo->prepare("SELECT quantite FROM stock_chantiers WHERE stock_id = ? AND chantier_id = ?");
    }
    $stmt->execute([$stockId, $sourceId]);
    $quantiteSource = (int)$stmt->fetchColumn();

    if ($quantiteSource < $qty) {
        throw new Exception("Stock insuffisant à la source. Disponible : {$quantiteSource}.");
    }

    // Réservations en attente (ignorer si la table n'existe pas)
    $enAttente = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(quantite),0) 
            FROM transferts_en_attente
            WHERE article_id = ? AND source_type = ? AND source_id = ? AND statut = 'en_attente'
        ");
        $stmt->execute([$stockId, $sourceType, $sourceId]);
        $enAttente = (int)$stmt->fetchColumn();
    } catch (Throwable $t) {
        $enAttente = 0;
    }

    $disponibleApresAttente = $quantiteSource - $enAttente;
    if ($disponibleApresAttente < $qty) {
        throw new Exception("Stock insuffisant (après réservations). Disponible : {$disponibleApresAttente}.");
    }

    // Enregistrer le transfert en attente de validation
    $stmt = $pdo->prepare("
        INSERT INTO transferts_en_attente 
            (article_id, source_type, source_id, destination_type, destination_id, quantite, demandeur_id, statut)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'en_attente')
    ");
    $stmt->execute([$stockId, $sourceType, $sourceId, $destinationType, $destinationId, $qty, $userId]);

    $pdo->commit();
    echo json_encode(["success" => true, "message" => "Transfert enregistré et en attente de validation."]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
