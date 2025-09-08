<?php
// Fichier : /stock/annulerTransfert_admin.php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';

// ---------- Accès ----------
if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? null) !== 'administrateur') {
    $_SESSION['error_message'] = "Accès refusé.";
    header("Location: stock_admin.php");
    exit;
}

// ---------- Multi-entreprise ----------
$ENT_ID = $_SESSION['utilisateurs']['entreprise_id'] ?? null;
$ENT_ID = is_numeric($ENT_ID) ? (int)$ENT_ID : null;

function me_where_first(?int $ENT_ID, string $alias = ''): array {
    if ($ENT_ID === null) return ['', []];
    $pfx = $alias ? $alias . '.' : '';
    return [" WHERE {$pfx}entreprise_id = :eid ", [':eid' => $ENT_ID]];
}
function me_where(?int $ENT_ID, string $alias = ''): array {
    if ($ENT_ID === null) return ['', []];
    $pfx = $alias ? $alias . '.' : '';
    return [" AND {$pfx}entreprise_id = :eid ", [':eid' => $ENT_ID]];
}
function belongs_or_fallback(PDO $pdo, string $table, int $id, ?int $ENT_ID): bool {
    if ($ENT_ID === null) return true;
    try {
        $st = $pdo->prepare("SELECT 1 FROM {$table} t WHERE t.id = :id AND t.entreprise_id = :eid");
        $st->execute([':id'=>$id, ':eid'=>$ENT_ID]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        // colonne entreprise_id absente -> fallback permissif
        return true;
    }
}

// ---------- Vérifs requête ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !isset($_POST['transfert_id'])) {
    $_SESSION['error_message'] = "Requête invalide.";
    header("Location: stock_admin.php");
    exit;
}

$transfertId = (int)$_POST['transfert_id'];
$adminId     = (int)($_SESSION['utilisateurs']['id'] ?? 0);

try {
    $pdo->beginTransaction();

    // 1) Charger le transfert en attente + verrouiller
    //    (filtré entreprise si possible)
    $sql = "
        SELECT t.*, s.nom AS article_nom
        FROM transferts_en_attente t
        JOIN stock s ON s.id = t.article_id
        WHERE t.id = :tid
          AND t.statut = 'en_attente'
        FOR UPDATE
    ";
    $params = [':tid' => $transfertId];

    if ($ENT_ID !== null) {
        try {
            $sql .= " AND t.entreprise_id = :eid";
            $params[':eid'] = $ENT_ID;
        } catch (Throwable $e) { /* fallback si colonne absente */ }
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transfert = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$transfert) {
        $pdo->rollBack();
        $_SESSION['error_message'] = "Transfert introuvable ou déjà traité.";
        header("Location: stock_admin.php");
        exit;
    }

    $articleId       = (int)$transfert['article_id'];
    $quantite        = (int)$transfert['quantite'];
    $sourceType      = $transfert['source_type'];          // 'depot' | 'chantier'
    $sourceId        = isset($transfert['source_id']) ? (int)$transfert['source_id'] : null;
    $destinationType = $transfert['destination_type'];     // 'depot' | 'chantier'
    $destinationId   = isset($transfert['destination_id']) ? (int)$transfert['destination_id'] : null;
    $demandeurId     = (int)$transfert['demandeur_id'];
    $articleNom      = (string)$transfert['article_nom'];

    // 1.b Garde-fous d'appartenance (si multi-entreprise actif)
    if ($ENT_ID !== null) {
        if (!belongs_or_fallback($pdo, 'stock', $articleId, $ENT_ID)) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Article hors de votre entreprise.";
            header("Location: stock_admin.php"); exit;
        }
        if ($sourceType === 'depot' && $sourceId && !belongs_or_fallback($pdo, 'depots', $sourceId, $ENT_ID)) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Source (dépôt) hors de votre entreprise.";
            header("Location: stock_admin.php"); exit;
        }
        if ($sourceType === 'chantier' && $sourceId && !belongs_or_fallback($pdo, 'chantiers', $sourceId, $ENT_ID)) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Source (chantier) hors de votre entreprise.";
            header("Location: stock_admin.php"); exit;
        }
        if ($destinationType === 'depot' && $destinationId && !belongs_or_fallback($pdo, 'depots', $destinationId, $ENT_ID)) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Destination (dépôt) hors de votre entreprise.";
            header("Location: stock_admin.php"); exit;
        }
        if ($destinationType === 'chantier' && $destinationId && !belongs_or_fallback($pdo, 'chantiers', $destinationId, $ENT_ID)) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Destination (chantier) hors de votre entreprise.";
            header("Location: stock_admin.php"); exit;
        }
    }

    // 2) Si la source = dépôt, on remet la quantité (car retirée à l'envoi)
    if ($sourceType === 'depot' && $sourceId) {
        // Upsert prudent (au cas où la ligne n'existe pas)
        $stmtCheck = $pdo->prepare("SELECT quantite FROM stock_depots WHERE stock_id = ? AND depot_id = ? FOR UPDATE");
        $stmtCheck->execute([$articleId, $sourceId]);
        $exists = $stmtCheck->fetchColumn();

        if ($exists !== false) {
            $stmt = $pdo->prepare("
                UPDATE stock_depots
                SET quantite = quantite + :qte
                WHERE stock_id = :article AND depot_id = :depot
            ");
            $stmt->execute([
                ':qte'     => $quantite,
                ':article' => $articleId,
                ':depot'   => $sourceId
            ]);
        } else {
            $insertDone = false;
            if ($ENT_ID !== null) {
                try {
                    $pdo->prepare("
                        INSERT INTO stock_depots (stock_id, depot_id, quantite, entreprise_id)
                        VALUES (:article, :depot, :qte, :eid)
                    ")->execute([
                        ':article'=>$articleId, ':depot'=>$sourceId, ':qte'=>$quantite, ':eid'=>$ENT_ID
                    ]);
                    $insertDone = true;
                } catch (Throwable $e) { /* fallback si colonne absente */ }
            }
            if (!$insertDone) {
                $pdo->prepare("
                    INSERT INTO stock_depots (stock_id, depot_id, quantite)
                    VALUES (:article, :depot, :qte)
                ")->execute([
                    ':article'=>$articleId, ':depot'=>$sourceId, ':qte'=>$quantite
                ]);
            }
        }
    }
    // (Si source = chantier, rien à remettre : pas de décrément à l’envoi)

    // 3) Historique : tracer l'annulation
    //    On stocke l’entreprise si possible
    $stmtMv = $pdo->prepare("
        INSERT INTO stock_mouvements
            (stock_id, type, source_type, source_id, dest_type, dest_id, quantite, statut, utilisateur_id, created_at
             ".($ENT_ID!==null ? ", entreprise_id" : "").")
        VALUES
            (:stock_id, 'transfert', :src_type, :src_id, :dest_type, :dest_id, :qte, 'annule', :user_id, NOW()
             ".($ENT_ID!==null ? ", :eid" : "").")
    ");
    $paramsMv = [
        ':stock_id'  => $articleId,
        ':src_type'  => $sourceType,
        ':src_id'    => $sourceId,
        ':dest_type' => $destinationType,
        ':dest_id'   => $destinationId,
        ':qte'       => $quantite,
        ':user_id'   => $adminId,
    ];
    if ($ENT_ID !== null) $paramsMv[':eid'] = $ENT_ID;
    $stmtMv->execute($paramsMv);

    // 4) Supprimer la demande en attente
    $stmt = $pdo->prepare("DELETE FROM transferts_en_attente WHERE id = ?");
    $stmt->execute([$transfertId]);

    // 5) Notifier le demandeur
    $message = "❌ Le transfert de {$quantite} x {$articleNom} a été annulé par l’administrateur.";
    if ($ENT_ID !== null) {
        try {
            $pdo->prepare("
                INSERT INTO notifications (utilisateur_id, message, entreprise_id)
                VALUES (?, ?, ?)
            ")->execute([$demandeurId, $message, $ENT_ID]);
        } catch (Throwable $e) {
            // fallback si colonne absente
            $pdo->prepare("INSERT INTO notifications (utilisateur_id, message) VALUES (?, ?)")
                ->execute([$demandeurId, $message]);
        }
    } else {
        $pdo->prepare("INSERT INTO notifications (utilisateur_id, message) VALUES (?, ?)")
            ->execute([$demandeurId, $message]);
    }

    // 6) Commit & UI
    $pdo->commit();
    $_SESSION['success_message'] = "Transfert annulé avec succès.";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['error_message'] = "Erreur lors de l’annulation : " . $e->getMessage();
}

header("Location: stock_admin.php");
exit;
