<?php
require_once "./config/init.php";

// 🔒 Sécurise session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 🔇 Désactive l'affichage d'erreurs PHP (elles sont loggées)
ini_set('display_errors', 0);
error_reporting(0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php_errors.log'); // Crée le dossier "logs" si pas présent

// 📡 Déclare le type de réponse JSON
header('Content-Type: application/json');

// 📥 Récupère les données POST
$stockId = isset($_POST['stock_id']) ? (int)$_POST['stock_id'] : null;
$destination = $_POST['destination'] ?? null;
$quantite = isset($_POST['quantite']) ? (int)$_POST['quantite'] : null;
$sourceChantier = $_POST['source'] ?? null;

$utilisateur = $_SESSION['utilisateurs'] ?? null;
$utilisateurChantierId = $utilisateur['chantier_id'] ?? null;
$fonction = $utilisateur['fonction'] ?? '';

// ✅ Validation
if (!$stockId || !$destination || $quantite === null || $quantite <= 0) {
    echo json_encode(['error' => 'Paramètres invalides']);
    exit;
}

// 🔎 Vérifie que l'article existe
$stmt = $pdo->prepare("SELECT quantite_disponible FROM stock WHERE id = ?");
$stmt->execute([$stockId]);
$stock = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$stock) {
    echo json_encode(['error' => 'Article introuvable']);
    exit;
}

$disponibleActuel = (int)$stock['quantite_disponible'];

// 🔀 Détermine le chantier source
$chantierSourceId = ($fonction === 'administrateur' && $sourceChantier) ? (int)$sourceChantier : (int)$utilisateurChantierId;

// 📦 Cas 1 : vers dépôt
if ($destination === 'depot') {
    // Ajoute au dépôt
    $stmt = $pdo->prepare("UPDATE stock SET quantite_disponible = quantite_disponible + ? WHERE id = ?");
    $stmt->execute([$quantite, $stockId]);

    // Retire du chantier
    $stmt = $pdo->prepare("UPDATE stock_chantiers SET quantite = quantite - ? WHERE stock_id = ? AND chantier_id = ?");
    $stmt->execute([$quantite, $stockId, $chantierSourceId]);

} else {
    // 🛠 Vers un autre chantier
    $destinationId = (int)$destination;

    // Stock suffisant ?
    if ($chantierSourceId === 0 && $quantite > $disponibleActuel) {
        echo json_encode(['error' => 'Stock insuffisant au dépôt']);
        exit;
    }

    // Déduction depuis le dépôt ou le chantier source
    if ($chantierSourceId === 0) {
        $stmt = $pdo->prepare("UPDATE stock SET quantite_disponible = quantite_disponible - ? WHERE id = ?");
        $stmt->execute([$quantite, $stockId]);
    } else {
        $stmt = $pdo->prepare("UPDATE stock_chantiers SET quantite = quantite - ? WHERE stock_id = ? AND chantier_id = ?");
        $stmt->execute([$quantite, $stockId, $chantierSourceId]);
    }

    // Ajout vers destination
    $stmt = $pdo->prepare("SELECT id FROM stock_chantiers WHERE stock_id = ? AND chantier_id = ?");
    $stmt->execute([$stockId, $destinationId]);

    if ($stmt->fetch()) {
        $stmt = $pdo->prepare("UPDATE stock_chantiers SET quantite = quantite + ? WHERE stock_id = ? AND chantier_id = ?");
        $stmt->execute([$quantite, $stockId, $destinationId]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO stock_chantiers (stock_id, chantier_id, quantite) VALUES (?, ?, ?)");
        $stmt->execute([$stockId, $destinationId, $quantite]);
    }
}

// 📊 Quantité utilisateur sur son chantier (si applicable)
if ($chantierSourceId) {
    $stmt = $pdo->prepare("SELECT quantite FROM stock_chantiers WHERE stock_id = ? AND chantier_id = ?");
    $stmt->execute([$stockId, $chantierSourceId]);
    $chantierQuantite = (int)($stmt->fetchColumn() ?? 0);
} else {
    $chantierQuantite = null;
}

// 📈 Récupère les stats globales
$stmt = $pdo->prepare("SELECT quantite_disponible FROM stock WHERE id = ?");
$stmt->execute([$stockId]);
$disponible = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT SUM(quantite) FROM stock_chantiers WHERE stock_id = ?");
$stmt->execute([$stockId]);
$surChantier = (int)($stmt->fetchColumn() ?? 0);

// 🔁 Si admin, retourne aussi HTML mis à jour pour la colonne "Chantiers"
$chantiersHtml = '';
if ($fonction === 'administrateur') {
    $stmt = $pdo->prepare("
        SELECT c.nom, sc.quantite 
        FROM stock_chantiers sc
        JOIN chantiers c ON sc.chantier_id = c.id
        WHERE sc.stock_id = ?
    ");
    $stmt->execute([$stockId]);
    $chantiers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($chantiers as $chantier) {
        $chantiersHtml .= "<div>" . htmlspecialchars($chantier['nom']) . " (" . (int)$chantier['quantite'] . ")</div>";
    }

    if (!$chantiersHtml) {
        $chantiersHtml = '<span class="text-muted">Aucun</span>';
    }
}

// 🔁 Met à jour la quantité totale = disponible + sur tous les chantiers
$stmt = $pdo->prepare("SELECT SUM(quantite) FROM stock_chantiers WHERE stock_id = ?");
$stmt->execute([$stockId]);
$totalChantiers = (int)($stmt->fetchColumn() ?? 0);


$quantiteTotale = $disponible + $totalChantiers;

$stmt = $pdo->prepare("UPDATE stock SET quantite_totale = ? WHERE id = ?");
$stmt->execute([$quantiteTotale, $stockId]);


// ✅ Réponse JSON
echo json_encode([
    'disponible' => $disponible,
    'surChantier' => $surChantier,
    'chantierQuantite' => $chantierQuantite ?? 0,
    'chantiersHtml' => $chantiersHtml
]);
