<?php
// Fichier: /stock/article.php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';

// --- Mode QR public ? ---
$qrToken = isset($_GET['t']) ? (string)$_GET['t'] : '';
$qrToken = (strlen($qrToken) >= 16 && strlen($qrToken) <= 64) ? $qrToken : '';
$isQrView = ($qrToken !== '');

// Si pas connecté ET pas en mode QR public => envoi vers index avec retour après login
if (!$isQrView && !isset($_SESSION['utilisateurs'])) {
    header("Location: ../index.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/navigation/navigation.php';

/* ================================
   Multi-entreprise helpers
================================ */
$ENT_ID = $_SESSION['utilisateurs']['entreprise_id'] ?? null;
$ENT_ID = is_numeric($ENT_ID) ? (int)$ENT_ID : null;

function belongs_or_fallback(PDO $pdo, string $table, int $id, ?int $ENT_ID): bool
{
    if ($ENT_ID === null) return true;
    try {
        $st = $pdo->prepare("SELECT 1 FROM {$table} t WHERE t.id = :id AND t.entreprise_id = :eid");
        $st->execute([':id' => $id, ':eid' => $ENT_ID]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return true;
    }
}

/* ================================
   Sélection article : par token (QR) ou par id
================================ */
$isLoggedIn = isset($_SESSION['utilisateurs']);
$fonction   = $_SESSION['utilisateurs']['fonction'] ?? null;
$userId     = (int)($_SESSION['utilisateurs']['id'] ?? 0);

$ENT_ID = $_SESSION['utilisateurs']['entreprise_id'] ?? null;
$ENT_ID = is_numeric($ENT_ID) ? (int)$ENT_ID : null;

$article   = null;
$articleId = 0;

if ($qrToken !== '') {
    // Accès public via QR : on récupère par token (sans régénération)
    $st = $pdo->prepare("SELECT * FROM stock WHERE qr_token = :t LIMIT 1");
    $st->execute([':t' => $qrToken]);
    $article = $st->fetch(PDO::FETCH_ASSOC);

    if (!$article) {
        echo "<div class='container mt-4 alert alert-danger'>QR invalide ou article introuvable.</div>";
        require_once __DIR__ . '/../templates/footer.php';
        exit;
    }

    $isQrView  = true;
    $articleId = (int)$article['id'];

    // Si visiteur non connecté, on calque ENT_ID sur l'article pour les filtrages en aval
    if (!$isLoggedIn) {
        $ENT_ID = (int)($article['entreprise_id'] ?? 0) ?: null;
    }
} else {
    // Accès connecté par ID
    if (!isset($_GET['id'])) {
        echo "<div class='container mt-4 alert alert-danger'>Aucun article sélectionné.</div>";
        require_once __DIR__ . '/../templates/footer.php';
        exit;
    }
    $articleId = (int)$_GET['id'];

    // Filtre multi-entreprise si ENT_ID connu
    $sql = "SELECT * FROM stock WHERE id = :id";
    $params = [':id' => $articleId];
    if ($ENT_ID !== null) {
        $sql .= " AND entreprise_id = :eid";
        $params[':eid'] = $ENT_ID;
    }
    $st = $pdo->prepare($sql . " LIMIT 1");
    $st->execute($params);
    $article = $st->fetch(PDO::FETCH_ASSOC);

    if (!$article) {
        echo "<div class='container mt-4 alert alert-danger'>Article introuvable.</div>";
        require_once __DIR__ . '/../templates/footer.php';
        exit;
    }
}

/* ================================
   QR token : figé (généré 1 seule fois)
================================ */
$qrTokenDb = (string)($article['qr_token'] ?? '');
if ($qrTokenDb === '') {
    // On crée un token permanent et on le stocke ; aucune route de "régénération"
    $qrTokenDb = bin2hex(random_bytes(16)); // 32 caractères
    $up = $pdo->prepare("UPDATE stock SET qr_token = :t WHERE id = :id");
    $up->execute([':t' => $qrTokenDb, ':id' => (int)$article['id']]);
    $article['qr_token'] = $qrTokenDb;
}

/* ================================
   URLs QR réutilisables (Voir / Imprimer)
================================ */
$qrPublicUrl = "/stock/article.php?t=" . urlencode($article['qr_token']); // vue publique
$qrImageUrl  = "/tools/qr.php?data=" . urlencode($qrPublicUrl);           // si tu génères un PNG pour l’impression


/* ================================
   Params + rôle + sécurités
================================ */
$chantierId = (int)($_GET['chantier_id'] ?? 0);
$depotId    = (int)($_GET['depot_id'] ?? 0);

$fonction = $_SESSION['utilisateurs']['fonction'] ?? null;
$userId   = (int)($_SESSION['utilisateurs']['id'] ?? 0);

/* Chef : ne voir que ses chantiers si chantier_id fourni */
if ($fonction === 'chef' && $chantierId > 0) {
    $stmt = $pdo->prepare("SELECT 1 FROM utilisateur_chantiers WHERE utilisateur_id = ? AND chantier_id = ? LIMIT 1");
    $stmt->execute([$userId, $chantierId]);
    if (!$stmt->fetchColumn()) {
        echo "<div class='container mt-4 alert alert-danger'>Accès refusé à ce chantier.</div>";
        require_once __DIR__ . '/../templates/footer.php';
        exit;
    }
}


$role = (string)($_SESSION['utilisateurs']['fonction'] ?? '');
function alerts_has_col(PDO $pdo, string $col): bool
{
    try {
        $q = $pdo->prepare("SHOW COLUMNS FROM stock_alerts LIKE :c");
        $q->execute([':c' => $col]);
        return (bool)$q->fetch();
    } catch (Throwable $e) {
        return false;
    }
}
$HAS_TARGET = alerts_has_col($pdo, 'target_role');
/* Dépôt : ne voir que son dépôt si depot_id fourni */
if ($fonction === 'depot' && $depotId > 0) {
    $stmt = $pdo->prepare("SELECT 1 FROM depots WHERE id = ? AND responsable_id = ? LIMIT 1");
    $stmt->execute([$depotId, $userId]);
    if (!$stmt->fetchColumn()) {
        echo "<div class='container mt-4 alert alert-danger'>Accès refusé à ce dépôt.</div>";
        require_once __DIR__ . '/../templates/footer.php';
        exit;
    }
}

/* ================================
   1) ARTICLE (filtré entreprise)
================================ */
try {
    $sql = "SELECT * FROM stock WHERE id = :id";
    $params = [':id' => $articleId];
    if ($ENT_ID !== null) {
        $sql .= " AND entreprise_id = :eid";
        $params[':eid'] = $ENT_ID;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $article = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $article = false;
}

if (!$article) {
    echo "<div class='container mt-4 alert alert-warning'>Article introuvable.</div>";
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

$isAdmin = $isLoggedIn && (($_SESSION['utilisateurs']['fonction'] ?? '') === 'administrateur');
$isDepot = $isLoggedIn && (($_SESSION['utilisateurs']['fonction'] ?? '') === 'depot');
$isChef  = $isLoggedIn && (($_SESSION['utilisateurs']['fonction'] ?? '') === 'chef');

$qrSimpleMode = ($isQrView && !$isLoggedIn);


/* ================================
   ALERTES EN COURS (anti-doublon)
================================ */
// Qui regarde la fiche ?
$isAdmin = isset($_SESSION['utilisateurs']) && (($_SESSION['utilisateurs']['fonction'] ?? '') === 'administrateur' || ($_SESSION['utilisateurs']['fonction'] ?? '') === 'admin');
$isDepot = isset($_SESSION['utilisateurs']) && (($_SESSION['utilisateurs']['fonction'] ?? '') === 'depot');
$isChef  = isset($_SESSION['utilisateurs']) && (($_SESSION['utilisateurs']['fonction'] ?? '') === 'chef');

// La table a-t-elle la colonne target_role ?

$HAS_TARGET = alerts_has_col($pdo, 'target_role');

// === Charger les alertes en évitant le doublon admin+depot ===
$sql = "
  SELECT
    a.id,
    a.type,
    a.message,
    a.created_at,
    a.is_read,
    a.archived_at,
    a.url,
    a.target_role,
    (
      SELECT ae.fichier
      FROM article_etats ae
      WHERE ae.article_id = a.stock_id
        AND ae.action = 'declarer_panne'
        AND ae.commentaire = a.message
      ORDER BY ae.id DESC
      LIMIT 1
    ) AS alert_file
  FROM stock_alerts a
  WHERE a.stock_id = :sid
    AND a.archived_at IS NULL
    AND a.type = 'incident'
    AND a.url IN ('problem','maintenance_due')
";

$params = [':sid' => (int)$articleId];

// Filtrer par rôle quand la colonne existe
if ($HAS_TARGET) {
    if ($isAdmin) $sql .= " AND a.target_role = 'admin'";
    elseif ($isDepot) $sql .= " AND a.target_role = 'depot'";
    elseif ($isChef)  $sql .= " AND a.target_role = 'chef'";
    else              $sql .= " AND a.target_role IS NULL"; // visiteur QR / autre rôle
}

$sql .= " ORDER BY a.created_at DESC, a.id DESC";

$st = $pdo->prepare($sql);
$st->execute($params);
$alerts = $st->fetchAll(PDO::FETCH_ASSOC);
// ===== Déduplication anti-twincast (admin+depot pour le même message/instant) =====
// Clé sémantique: type | url | message | minute(created_at)
// Retiens de préférence la version "non lue", sinon la plus récente (id max).
$alerts = (function (array $rows) {
    $byKey = [];
    foreach ($rows as $r) {
        $key = implode('|', [
            (string)($r['type'] ?? ''),
            (string)($r['url'] ?? ''),
            trim((string)($r['message'] ?? '')),
            substr((string)($r['created_at'] ?? ''), 0, 16) // précision à la minute
        ]);

        if (!isset($byKey[$key])) {
            $byKey[$key] = $r;
            continue;
        }

        $keep     = $byKey[$key];
        $keepUnread = ((int)($keep['is_read'] ?? 0) === 0);
        $currUnread = ((int)($r['is_read'] ?? 0) === 0);

        // Priorité au "non lu", sinon on garde l'id le plus grand
        if ($currUnread && !$keepUnread) {
            $byKey[$key] = $r;
        } elseif ((int)($r['id'] ?? 0) > (int)($keep['id'] ?? 0)) {
            $byKey[$key] = $r;
        }
    }
    return array_values($byKey);
})($alerts);


/* ================================
   Heures compteur : variables dédiées
================================ */
$maintenanceMode = $article['maintenance_mode'] ?? 'none';
$qrTokenForPost  = (string)($article['qr_token'] ?? '');

$hasHourMeter = ((int)($article['has_hour_meter'] ?? 0) === 1)
    || ($maintenanceMode === 'hour_meter')
    || (($article['profil_qr'] ?? '') === 'compteur_heures');

$hourUnit = ($article['hour_meter_unit'] ?? 'h') ?: 'h';
$hourInit = isset($article['hour_meter_initial']) ? (int)$article['hour_meter_initial'] : 0;
// === Faut-il afficher l’onglet "Historique des états" ? ===
$hasEtatRows = false;
try {
    $st = $pdo->prepare("SELECT 1 FROM article_etats WHERE article_id = :aid LIMIT 1");
    $st->execute([':aid' => (int)$articleId]);
    $hasEtatRows = (bool)$st->fetchColumn();
} catch (Throwable $e) {
    $hasEtatRows = false;
}
$showEtatTab = ($maintenanceMode !== 'none') || $hasHourMeter || $hasEtatRows;

// Dernier relevé global
$lastHours = $hourInit;
try {
    $stH = $pdo->prepare("SELECT hours FROM stock_hour_logs WHERE stock_id=? ORDER BY created_at DESC, id DESC LIMIT 1");
    $stH->execute([$articleId]);
    $h = $stH->fetchColumn();
    if ($h !== false) $lastHours = (int)$h;
} catch (Throwable $e) {
}

$contextChantier = ($chantierId > 0) ? $chantierId : null;
if ($isChef && !$contextChantier) {
    // Si le chef n’a pas ?chantier_id, on prend son premier chantier
    $stC = $pdo->prepare("SELECT chantier_id FROM utilisateur_chantiers WHERE utilisateur_id=? ORDER BY chantier_id ASC LIMIT 1");
    $stC->execute([$userId]);
    $contextChantier = (int)($stC->fetchColumn() ?: 0) ?: null;
}

/* ================================
   2) QUANTITÉS
================================ */
try {
    $sql = "
        SELECT COALESCE(SUM(sd.quantite),0)
        FROM stock_depots sd
        JOIN depots d ON d.id = sd.depot_id
        WHERE sd.stock_id = :sid
    ";
    $params = [':sid' => $articleId];
    if ($ENT_ID !== null) {
        $sql .= " AND d.entreprise_id = :eid";
        $params[':eid'] = $ENT_ID;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $quantiteDepot = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantite),0) FROM stock_depots WHERE stock_id = ?");
    $stmt->execute([$articleId]);
    $quantiteDepot = (int)$stmt->fetchColumn();
}

try {
    $sql = "
        SELECT COALESCE(SUM(sc.quantite),0)
        FROM stock_chantiers sc
        JOIN chantiers c ON c.id = sc.chantier_id
        WHERE sc.stock_id = :sid
    ";
    $params = [':sid' => $articleId];
    if ($ENT_ID !== null) {
        $sql .= " AND c.entreprise_id = :eid";
        $params[':eid'] = $ENT_ID;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $quantiteChantier = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantite),0) FROM stock_chantiers WHERE stock_id = ?");
    $stmt->execute([$articleId]);
    $quantiteChantier = (int)$stmt->fetchColumn();
}

$totalQuantite = $quantiteDepot + $quantiteChantier;

/* Quantité du contexte (bandeau) */
$currentQtyContext   = null;
$currentLabelContext = null;

if ($chantierId > 0) {
    if ($ENT_ID !== null && !belongs_or_fallback($pdo, 'chantiers', $chantierId, $ENT_ID)) {
        echo "<div class='container mt-4 alert alert-danger'>Chantier hors de votre entreprise.</div>";
        require_once __DIR__ . '/../templates/footer.php';
        exit;
    }
    $stmt = $pdo->prepare("SELECT COALESCE(quantite,0) FROM stock_chantiers WHERE stock_id = ? AND chantier_id = ?");
    $stmt->execute([$articleId, $chantierId]);
    $currentQtyContext   = (int)$stmt->fetchColumn();
    $currentLabelContext = "Quantité sur ce chantier";
}
if ($depotId > 0) {
    if ($ENT_ID !== null && !belongs_or_fallback($pdo, 'depots', $depotId, $ENT_ID)) {
        echo "<div class='container mt-4 alert alert-danger'>Dépôt hors de votre entreprise.</div>";
        require_once __DIR__ . '/../templates/footer.php';
        exit;
    }
    $stmt = $pdo->prepare("SELECT COALESCE(quantite,0) FROM stock_depots WHERE stock_id = ? AND depot_id = ?");
    $stmt->execute([$articleId, $depotId]);
    $currentQtyContext   = (int)$stmt->fetchColumn();
    $currentLabelContext = "Quantité dans ce dépôt";
}

/* Contexte noms (pour les badges en haut) */
if ($chantierId > 0) {
    $sql = "SELECT nom FROM chantiers WHERE id = ?";
    $params = [$chantierId];
    if ($ENT_ID !== null) {
        $sql .= " AND entreprise_id = ?";
        $params[] = $ENT_ID;
    }
    $sql .= " LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $chantierNomCtx = $stmt->fetchColumn() ?: null;
}
if ($depotId > 0) {
    $sql = "SELECT nom FROM depots WHERE id = ?";
    $params = [$depotId];
    if ($ENT_ID !== null) {
        $sql .= " AND entreprise_id = ?";
        $params[] = $ENT_ID;
    }
    $sql .= " LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $depotNomCtx = $stmt->fetchColumn() ?: null;
}

/* ================================
   3) RÉPARTITION PAR CHANTIER (>0)
================================ */
try {
    $sql = "
        SELECT sc.chantier_id, c.nom AS chantier_nom, sc.quantite
        FROM stock_chantiers sc
        JOIN chantiers c ON c.id = sc.chantier_id
        WHERE sc.stock_id = :sid
          AND sc.quantite > 0
    ";
    $params = [':sid' => $articleId];
    if ($ENT_ID !== null) {
        $sql .= " AND c.entreprise_id = :eid";
        $params[':eid'] = $ENT_ID;
    }
    $sql .= " ORDER BY c.nom ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $quantitesParChantier = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $stmt = $pdo->prepare("
        SELECT sc.chantier_id, c.nom AS chantier_nom, sc.quantite
        FROM stock_chantiers sc
        JOIN chantiers c ON c.id = sc.chantier_id
        WHERE sc.stock_id = ?
          AND sc.quantite > 0
        ORDER BY c.nom ASC
    ");
    $stmt->execute([$articleId]);
    $quantitesParChantier = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ================================
   4) DOCUMENTS LIÉS
================================ */
$stmt = $pdo->prepare("
    SELECT id, nom_affichage, chemin_fichier, type_mime, taille, created_at
    FROM stock_documents
    WHERE stock_id = ?
    ORDER BY created_at DESC, id DESC
");
$stmt->execute([$articleId]);
$articleFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

function file_icon_from_mime(?string $mime, string $fallback = '📄'): string
{
    if (!$mime) return $fallback;
    $m = strtolower($mime);
    if (str_starts_with($m, 'image/')) return '🖼️';
    if ($m === 'application/pdf') return '📕';
    if (str_contains($m, 'word') || str_contains($m, 'officedocument.wordprocessingml')) return '📝';
    if (str_contains($m, 'excel') || str_contains($m, 'spreadsheetml')) return '📊';
    if (str_contains($m, 'powerpoint') || str_contains($m, 'presentationml')) return '📽️';
    if (str_starts_with($m, 'text/')) return '📃';
    if (str_contains($m, 'zip') || str_contains($m, 'rar') || str_contains($m, '7z')) return '🗜️';
    return $fallback;
}
function human_filesize(?int $bytes): string
{
    if ($bytes === null) return '-';
    $units = ['o', 'Ko', 'Mo', 'Go', 'To'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 1) . ' ' . $units[$i];
}

/* ================================
   5) HISTORIQUE + pagination
================================ */
$perPage = 10;
$page    = max(1, (int)($_GET['hpage'] ?? 1));
$offset  = ($page - 1) * $perPage;

try {
    $sqlCnt = "SELECT COUNT(*) FROM stock_mouvements sm WHERE sm.stock_id = :sid";
    $paramsCnt = [':sid' => $articleId];
    if ($ENT_ID !== null) {
        $sqlCnt .= " AND sm.entreprise_id = :eid";
        $paramsCnt[':eid'] = $ENT_ID;
    }
    $stmtCnt = $pdo->prepare($sqlCnt);
    $stmtCnt->execute($paramsCnt);
    $totalRows = (int)$stmtCnt->fetchColumn();
} catch (Throwable $e) {
    $stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM stock_mouvements WHERE stock_id = :sid");
    $stmtCnt->execute([':sid' => $articleId]);
    $totalRows = (int)$stmtCnt->fetchColumn();
}
function historyUrl(int $p): string
{
    $qs = $_GET;
    $qs['hpage'] = $p;
    return basename($_SERVER['PHP_SELF']) . '?' . http_build_query($qs) . '#history';
}

$mouvements = [];
try {
    $sql = "
    SELECT
        sm.*,
        us.prenom AS user_prenom, us.fonction AS user_fonction,
        d_us.nom  AS validateur_depot_nom,
        c_us.nom  AS validateur_chantier_nom,
        dem.prenom AS dem_prenom, dem.fonction AS dem_fonction,
        cs.nom AS source_chantier_nom, cd.nom AS dest_chantier_nom,
        ds.nom AS source_depot_nom,    dd.nom AS dest_depot_nom,
        us_src.prenom AS src_respo_prenom, uc_src_u.prenom AS src_chef_prenom,
        us_dst.prenom AS dst_respo_prenom, uc_dst_u.prenom AS dst_chef_prenom,
        CASE WHEN dem.fonction='administrateur' THEN dem.prenom
             WHEN sm.source_type='depot' THEN us_src.prenom
             WHEN sm.source_type='chantier' THEN uc_src_u.prenom
             ELSE NULL END AS src_actor_prenom,
        CASE WHEN sm.dest_type='depot' THEN us_dst.prenom
             WHEN sm.dest_type='chantier' THEN uc_dst_u.prenom
             ELSE NULL END AS dst_actor_prenom
    FROM stock_mouvements sm
    LEFT JOIN utilisateurs us ON us.id = sm.utilisateur_id
    LEFT JOIN depots d_us ON (us.fonction='depot' AND d_us.responsable_id = us.id)
    LEFT JOIN (SELECT uc.utilisateur_id, MIN(uc.chantier_id) AS chantier_id FROM utilisateur_chantiers uc GROUP BY uc.utilisateur_id) uc_us
           ON (us.fonction='chef' AND uc_us.utilisateur_id = us.id)
    LEFT JOIN chantiers c_us ON (c_us.id = uc_us.chantier_id)
    LEFT JOIN utilisateurs dem ON dem.id = sm.demandeur_id
    LEFT JOIN chantiers cs ON (sm.source_type='chantier' AND cs.id = sm.source_id)
    LEFT JOIN chantiers cd ON (sm.dest_type='chantier' AND cd.id = sm.dest_id)
    LEFT JOIN depots ds ON (sm.source_type='depot' AND ds.id = sm.source_id)
    LEFT JOIN depots dd ON (sm.dest_type='depot' AND dd.id = sm.dest_id)
    LEFT JOIN utilisateurs us_src ON (sm.source_type='depot' AND us_src.id = ds.responsable_id)
    LEFT JOIN (SELECT uc.chantier_id, MIN(uc.utilisateur_id) AS chef_id FROM utilisateur_chantiers uc GROUP BY uc.chantier_id) uc_src
           ON (sm.source_type='chantier' AND uc_src.chantier_id = sm.source_id)
    LEFT JOIN utilisateurs uc_src_u ON (uc_src_u.id = uc_src.chef_id)
    LEFT JOIN utilisateurs us_dst ON (sm.dest_type='depot' AND us_dst.id = dd.responsable_id)
    LEFT JOIN (SELECT uc.chantier_id, MIN(uc.utilisateur_id) AS chef_id FROM utilisateur_chantiers uc GROUP BY uc.chantier_id) uc_dst
           ON (sm.dest_type='chantier' AND uc_dst.chantier_id = sm.dest_id)
    LEFT JOIN utilisateurs uc_dst_u ON (uc_dst_u.id = uc_dst.chef_id)
    WHERE sm.stock_id = :sid
";
    $params = [':sid' => $articleId];
    if ($ENT_ID !== null) {
        $sql .= " AND sm.entreprise_id = :eid";
        $params[':eid'] = $ENT_ID;
    }
    $sql .= " ORDER BY sm.created_at DESC, sm.id DESC LIMIT $perPage OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $mouvements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $sql = "
    SELECT
        sm.*,
        us.prenom AS user_prenom, us.fonction AS user_fonction,
        d_us.nom  AS validateur_depot_nom,
        c_us.nom  AS validateur_chantier_nom,
        dem.prenom AS dem_prenom, dem.fonction AS dem_fonction,
        cs.nom AS source_chantier_nom, cd.nom AS dest_chantier_nom,
        ds.nom AS source_depot_nom,    dd.nom AS dest_depot_nom,
        us_src.prenom AS src_respo_prenom, uc_src_u.prenom AS src_chef_prenom,
        us_dst.prenom AS dst_respo_prenom, uc_dst_u.prenom AS dst_chef_prenom
    FROM stock_mouvements sm
    LEFT JOIN utilisateurs us ON us.id = sm.utilisateur_id
    LEFT JOIN depots d_us ON (us.fonction='depot' AND d_us.responsable_id = us.id)
    WHERE sm.stock_id = :sid
    ORDER BY sm.created_at DESC, sm.id DESC
    LIMIT $perPage OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':sid' => $articleId]);
    $mouvements = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function label_personne_lieu(?string $type, array $row, string $prefix): string
{
    $actorPrenom = $row[$prefix === 'source' ? 'src_actor_prenom' : 'dst_actor_prenom'] ?? null;
    if ($type === 'depot') {
        $depot = $row[$prefix === 'source' ? 'source_depot_nom' : 'dest_depot_nom'] ?? null;
        if ($actorPrenom && $depot) return htmlspecialchars("$actorPrenom (dépôt $depot)");
        if ($depot) return htmlspecialchars("Dépôt ($depot)");
        return 'Dépôt';
    }
    if ($type === 'chantier') {
        $chantier = $row[$prefix === 'source' ? 'source_chantier_nom' : 'dest_chantier_nom'] ?? null;
        if ($actorPrenom && $chantier) return htmlspecialchars("$actorPrenom (chantier $chantier)");
        if ($chantier) return htmlspecialchars("Chantier : $chantier");
        return 'Chantier';
    }
    return '-';
}
function badge_statut(string $statut): string
{
    $map = ['valide' => 'success', 'refuse' => 'danger', 'annule' => 'danger'];
    $cls = $map[$statut] ?? 'secondary';
    return "<span class=\"badge bg-$cls text-uppercase\">$statut</span>";
}
function label_validateur(array $row): string
{
    $prenom = trim($row['user_prenom'] ?? '');
    if ($prenom === '') return '-';
    $suffix = '';
    switch ($row['user_fonction'] ?? '') {
        case 'depot':
            if (!empty($row['validateur_depot_nom'])) $suffix = ' (dépôt ' . $row['validateur_depot_nom'] . ')';
            break;
        case 'chef':
            $ch = $row['validateur_chantier_nom'] ?? $row['source_chantier_nom'] ?? $row['dest_chantier_nom'] ?? null;
            if ($ch) $suffix = ' (chantier ' . $ch . ')';
            break;
        case 'administrateur':
            $suffix = '';
            break;
    }
    return htmlspecialchars($prenom . $suffix);
}

// Actions (article_etats)
$etatLogs = [];
$st = $pdo->prepare("
  SELECT id, profil_qr, action, valeur_int, commentaire, fichier, created_by, created_at
  FROM article_etats
  WHERE article_id = :sid " . ($ENT_ID ? " AND entreprise_id=:eid " : "") . "
  ORDER BY created_at DESC, id DESC
  LIMIT 200
");
$p = [':sid' => $articleId];
if ($ENT_ID) $p[':eid'] = $ENT_ID;
$st->execute($p);
$etatLogs = $st->fetchAll(PDO::FETCH_ASSOC);

// Alertes archivées
$archivedAlerts = [];
$st = $pdo->prepare("
  SELECT id, type, url, message, created_at, archived_at, archived_by
  FROM stock_alerts
  WHERE stock_id = :sid
    AND archived_at IS NOT NULL
    AND type IN ('incident','maintenance')
  ORDER BY archived_at DESC, created_at DESC, id DESC
  LIMIT 200
");
$st->execute([':sid' => $articleId]);
$archivedAlerts = $st->fetchAll(PDO::FETCH_ASSOC);

// --- Répartition de l'article par chantier (quantités > 0)
$chantiersLoc = [];
try {
    $sql = "
        SELECT sc.chantier_id, c.nom AS chantier_nom, sc.quantite
        FROM stock_chantiers sc
        JOIN chantiers c ON c.id = sc.chantier_id
        WHERE sc.stock_id = :aid
          AND sc.quantite > 0
          " . ($ENT_ID ? " AND c.entreprise_id = :eid " : "") . "
        ORDER BY c.nom ASC
    ";
    $st = $pdo->prepare($sql);
    $st->bindValue(':aid', (int)$articleId, PDO::PARAM_INT);
    if ($ENT_ID) $st->bindValue(':eid', (int)$ENT_ID, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $q = (int)$r['quantite'];
        $nom = (string)$r['chantier_nom'];
        // Formattage "Nom : 3 u" (avec pluriel simple)
        $chantiersLoc[] = $nom . ' : ' . $q . ' unité' . ($q > 1 ? 's' : '');
    }
} catch (Throwable $e) {
    // silencieux, on garde la page fonctionnelle
    $chantiersLoc = [];
}


?>

<?php if ($qrSimpleMode): ?>
    <div class="container py-3">
        <h1 class="h5 mb-1"><?= htmlspecialchars($article['nom'] ?? 'Article') ?></h1>
        <div class="text-muted mb-3">Réf. <?= htmlspecialchars($article['reference'] ?? '—') ?></div>
        <?php
        try {
            $qDepot = (int)$pdo->query("SELECT COALESCE(SUM(sd.quantite),0) FROM stock_depots sd JOIN depots d ON d.id=sd.depot_id WHERE sd.stock_id={$articleId}" . ($ENT_ID ? " AND d.entreprise_id={$ENT_ID}" : ''))->fetchColumn();
        } catch (Throwable $e) {
            $qDepot = 0;
        }
        try {
            $qCh    = (int)$pdo->query("SELECT COALESCE(SUM(sc.quantite),0) FROM stock_chantiers sc JOIN chantiers c ON c.id=sc.chantier_id WHERE sc.stock_id={$articleId}" . ($ENT_ID ? " AND c.entreprise_id={$ENT_ID}" : ''))->fetchColumn();
        } catch (Throwable $e) {
            $qCh = 0;
        }
        $qTot = $qDepot + $qCh;
        ?>
        <div class="card mb-3">
            <div class="card-body d-flex gap-3 flex-wrap align-items-start">
                <div><span class="text-muted">Total :</span> <strong><?= $qTot ?></strong></div>

                <div>
                    <span class="text-muted">Chantiers :</span> <strong><?= (int)$quantiteChantier ?></strong>

                    <?php if (!empty($chantiersLoc)): ?>
                        <div class="small text-muted mt-1">
                            <i class="bi bi-geo-alt"></i>
                            <?= htmlspecialchars(implode(' · ', $chantiersLoc), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php else: ?>
                        <div class="small text-muted mt-1">
                            <i class="bi bi-geo-alt"></i> Aucun chantier
                        </div>
                    <?php endif; ?>
                </div>


                <div><span class="text-muted">Dépôts :</span> <strong><?= $qDepot ?></strong></div>
            </div>
        </div>


        <?php if ($maintenanceMode === 'hour_meter' || $hasHourMeter): ?>
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="mb-2">Relevé compteur (heures)</h5>
                    <form class="row g-2" id="formHourQR">
                        <input type="hidden" name="action" value="hour_meter">
                        <input type="hidden" name="stock_id" value="<?= (int)$articleId ?>">
                        <!-- Prérempli avec la valeur initiale -->
                        <div class="col-auto">
                            <input type="number" min="0" step="1" class="form-control" name="hours" value="<?= $lastHours ?>">
                        </div>
                        <div class="col-auto"><button class="btn btn-primary">Enregistrer</button></div>
                    </form>
                    <small class="text-muted d-block mt-2">
                        Dernier relevé : <?= $lastHours ?> <?= htmlspecialchars($hourUnit) ?>
                        (saisis la valeur totale du compteur. Si tu entres une petite valeur, on la traitera comme un +incrément)
                    </small>
                    <div id="declareMsgQR" class="alert d-none mt-2"></div>
                </div>
            </div>
        <?php elseif ($maintenanceMode === 'electrical'): ?>
            <?php
            // QR public : charger les alertes (incidents + maintenance) non archivées
            $alerts = [];
            try {
                $qa = $pdo->prepare("
                  SELECT id, type, message, is_read, created_at, url, archived_at
                  FROM stock_alerts
                  WHERE stock_id = :sid
                    AND archived_at IS NULL
                    AND type IN ('incident','maintenance')
                  ORDER BY created_at DESC, id DESC
                ");
                $qa->execute([':sid' => $articleId]);
                $alerts = $qa->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $alerts = [];
            }
            // Etat basé sur archivage (is_read != résolu)
            $hasOpen = false;
            foreach ($alerts as $a) {
                if (empty($a['archived_at'])) {
                    $hasOpen = true;
                    break;
                }
            }
            ?>
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="mb-2">Statut</h5>
                    <div>
                        État :
                        <?php if ($hasOpen): ?>
                            <span class="badge bg-danger">Problème</span>
                        <?php else: ?>
                            <span class="badge bg-success">OK</span>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($alerts)): ?>
                        <ul class="list-group list-group-flush mt-2">
                            <?php foreach ($alerts as $a): ?>
                                <?php
                                $isArchived = !empty($a['archived_at']);
                                $isUnread   = ((int)($a['is_read'] ?? 0) === 0);
                                ?>
                                <li class="list-group-item d-flex justify-content-between align-items-start">
                                    <div class="me-3">
                                        <div class="<?= (!$isArchived && $isUnread) ? 'fw-semibold' : 'text-muted' ?>">
                                            <?= nl2br(htmlspecialchars($a['message'] ?? '')) ?>
                                        </div>
                                        <small class="text-muted">
                                            <?= date('d/m/Y H:i', strtotime($a['created_at'])) ?>
                                        </small>
                                        <?php if ($isArchived): ?>
                                            <span class="badge bg-secondary ms-2">archivée</span>
                                        <?php elseif (!$isUnread): ?>
                                            <span class="badge bg-light text-muted border ms-2">lu</span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($isAdmin && !$isArchived): ?>
                                        <button class="btn btn-sm btn-success btn-resolve-one" data-alert-id="<?= (int)$a['id'] ?>">
                                            Marquer résolu
                                        </button>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <small class="text-muted d-block mt-2">Aucune alerte enregistrée.</small>
                    <?php endif; ?>

                    <button class="btn btn-danger w-100 mt-3" data-bs-toggle="modal" data-bs-target="#declareModal">
                        Déclarer un problème
                    </button>
                    <div id="declareMsgQR" class="alert d-none mt-2"></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="d-grid gap-2">
            <?php $loginUrl = "/index.php?redirect=" . urlencode($qrPublicUrl); ?>
            <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($loginUrl) ?>">Se connecter</a>
        </div>
    </div>



    <!-- Modale déclaration (QR public) -->
    <div class="modal fade" id="declareModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Déclarer un problème</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="formDeclareQR">
                        <input type="hidden" id="panneStockId" value="<?= (int)$articleId ?>">
                        <input type="hidden" name="action" value="declare_problem">
                        <input type="hidden" name="stock_id" value="<?= (int)$articleId ?>">
                        <div class="mb-2">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="message" rows="4" required
                                placeholder="Décrivez brièvement le souci (ex. : fuite, problème electrique…)"></textarea>
                        </div>
                    </form>
                    <div class="alert alert-light border small d-none" id="declareMsg"></div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                    <button class="btn btn-primary" id="btnSendDeclareQR">Envoyer</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Helpers fetch: texte -> JSON
        async function postUrlEncoded(data) {
            const res = await fetch('/stock/ajax/ajax_article_etat_save.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams(data),
                credentials: 'same-origin'
            });
            const raw = await res.text();
            let json;
            try {
                json = JSON.parse(raw);
            } catch {
                throw new Error('Réponse non-JSON du serveur:\n' + raw);
            }
            if (!res.ok || json.ok === false) throw new Error(json.msg || json.error || 'Erreur serveur');
            return json;
        }

        (async function() {
            const fh = document.getElementById('formHourQR');
            const boxQR = document.getElementById('declareMsgQR');
            const LAST = <?= (int)$lastHours ?>;

            async function postUrlEncoded(data) {
                const res = await fetch('/stock/ajax/ajax_article_etat_save.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams(data),
                    credentials: 'same-origin'
                });
                const raw = await res.text();
                let json;
                try {
                    json = JSON.parse(raw);
                } catch {
                    throw new Error('Réponse non-JSON:\n' + raw);
                }
                if (!res.ok || json.ok === false) throw new Error(json.msg || json.error || 'Erreur serveur');
                return json;
            }

            if (fh) {
                fh.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    const fd = new FormData(fh);
                    const data = Object.fromEntries(fd.entries());

                    // 👇 Tolérance incrément côté client : si la saisie est < au dernier relevé, on la considère comme +incrément
                    let v = parseInt(data.hours, 10);
                    if (!Number.isFinite(v) || v < 0) {
                        boxQR?.classList.remove('d-none');
                        if (boxQR) {
                            boxQR.className = 'alert alert-danger mt-2';
                            boxQR.textContent = 'Valeur de compteur invalide';
                        }
                        return;
                    }
                    if (v < LAST) {
                        v = LAST + v; // interprète comme +incrément
                    }
                    data.hours = String(v);

                    const btn = fh.querySelector('button');
                    btn.disabled = true;
                    try {
                        await postUrlEncoded(data);
                        location.reload();
                    } catch (err) {
                        if (boxQR) {
                            boxQR.className = 'alert alert-danger mt-2';
                            boxQR.textContent = err.message;
                            boxQR.classList.remove('d-none');
                        } else {
                            alert(err.message);
                        }
                    } finally {
                        btn.disabled = false;
                    }
                });
            }
        })();
    </script>

    <?php require_once __DIR__ . '/../templates/footer.php';
    return; ?>
<?php endif; ?>

<div class="container mt-4">
    <!-- En-tête -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="javascript:history.back()" class="btn btn-outline-secondary">← Retour</a>
        <div>
            <h2 class="mb-0 fw-bold"><?= ucfirst(htmlspecialchars(strtolower($article['nom']))) ?></h2>
            <small class="text-muted">
                Catégorie : <?= htmlspecialchars($article['categorie'] ?? '-') ?> > <?= htmlspecialchars($article['sous_categorie'] ?? '-') ?>
            </small>
        </div>
        <div class="d-flex gap-2">
            <?php if ($chantierId > 0): ?>
                <span class="badge bg-secondary">Chantier : <?= htmlspecialchars($chantierNomCtx ?? ("#" . $chantierId)) ?></span>
            <?php endif; ?>
            <?php if ($depotId > 0): ?>
                <span class="badge bg-info">Dépôt : <?= htmlspecialchars($depotNomCtx ?? ("#" . $depotId)) ?></span>
            <?php endif; ?>
        </div>
        <?php if ($isAdmin): ?>
            <div class="ms-2 d-flex align-items-center gap-2">
                <?php if (empty($article['qr_token'])): ?>
                    <form method="post" action="/stock/stock_generate_qr.php" class="d-inline">
                        <input type="hidden" name="id" value="<?= (int)$article['id'] ?>">
                        <button class="btn btn-sm btn-outline-primary">Générer QR</button>
                    </form>
                <?php else: ?>
                    <a class="btn btn-sm btn-outline-secondary" target="_blank"
                        href="/stock/qr_render.php?t=<?= urlencode($article['qr_token']) ?>">Voir QR</a>
                    <button class="btn btn-sm btn-success" onclick="window.print()">Imprimer</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>

    <!-- Cartes quantités + photo -->
    <div class="card mb-3 p-4 bg-body-tertiary border-0 shadow-sm">
        <div class="row align-items-center g-3">

            <!-- PHOTO À GAUCHE -->
            <div class="col-md-4">
                <div class="card shadow-sm">
                    <?php if (!empty($article['photo'])): ?>
                        <?php $photoUrl = '/' . ltrim($article['photo'], '/'); ?>
                        <img src="<?= htmlspecialchars($photoUrl) ?>" class="card-img-top img-fluid" alt="Photo de l'article" style="max-height: 320px; object-fit: contain;">
                    <?php else: ?>
                        <div class="text-muted text-center p-4">Aucune photo disponible</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- QUANTITÉS CENTRÉES À DROITE -->
            <div class="col-md-8">
                <div class="text-center mb-3">
                    <h5 class="fw-bold">Quantités disponibles</h5>
                </div>

                <div class="d-flex justify-content-center flex-wrap text-center">

                    <!-- Total -->
                    <div class="p-3 rounded-3 shadow-sm mx-2 mb-2"
                        style="min-width:180px; background-color:#e0e0e0;">
                        <div class="fw-bold fs-4 text-dark"><?= (int)$totalQuantite ?></div>
                        <div class="text-secondary">Total</div>
                    </div>

                    <!-- Sur chantiers -->
                    <div class="p-3 rounded-3 shadow-sm mx-2 mb-2"
                        style="min-width:180px; background-color:#e0e0e0;">
                        <div class="fw-bold fs-4 text-dark"><?= (int)$quantiteChantier ?></div>
                        <div class="text-secondary">Sur chantiers</div>

                        <?php if (!empty($quantitesParChantier)): ?>
                            <ul class="list-unstyled mb-0 small mt-2 text-muted" style="line-height:1.25; max-height:120px; overflow:auto;">
                                <?php foreach ($quantitesParChantier as $r): ?>
                                    <?php
                                    $id  = (int)$r['chantier_id'];
                                    $nom = htmlspecialchars($r['chantier_nom'], ENT_QUOTES, 'UTF-8');
                                    $q   = (int)$r['quantite'];
                                    $label = $nom . ' : ' . $q . ' unité' . ($q > 1 ? 's' : '');
                                    ?>
                                    <li class="d-flex align-items-start gap-1">
                                        <i class="bi bi-geo-alt mt-1"></i>
                                        <a href="/chantiers/chantier_contenu.php?id=<?= $id ?>"
                                            class="text-decoration-none text-primary"><?= $label ?></a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="small mt-1 text-muted"><i class="bi bi-geo-alt"></i> Aucun chantier</div>
                        <?php endif; ?>

                    </div>

                    <!-- En dépôt -->
                    <div class="p-3 rounded-3 shadow-sm mx-2 mb-2"
                        style="min-width:180px; background-color:#e0e0e0;">
                        <div class="fw-bold fs-4 text-dark"><?= (int)$quantiteDepot ?></div>
                        <div class="text-secondary">En dépôt</div>
                    </div>

                </div>
            </div>

        </div>
    </div>




    <!-- Onglets -->
    <ul class="nav nav-tabs mb-3" id="articleTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="details-tab" data-bs-toggle="tab" data-bs-target="#details" type="button" role="tab">Détails</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="files-tab" data-bs-toggle="tab" data-bs-target="#files" type="button" role="tab">Documents</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab">Historique des transferts</button>
        </li>

        <?php if ($showEtatTab): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#etatlog" type="button" role="tab">
                    Historique des états
                </button>
            </li>
        <?php endif; ?>
    </ul>


    <div class="tab-content" id="articleTabContent">
        <!-- Détails -->
        <div class="tab-pane fade show active" id="details" role="tabpanel">
            <div class="card shadow-sm p-3">
                <h5 class="fw-bold">Caractéristiques de l'article</h5>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex align-items-center justify-content-between">
                        <div>
                            <span class="fw-semibold">Référence :</span>
                            <span id="articleRefValue">
                                <?= htmlspecialchars((string)($article['reference'] ?? '-')) ?: '-' ?>
                            </span>
                        </div>

                        <?php if (!empty($isAdmin) && $isAdmin && empty($isQrView)): ?>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-primary"
                                id="btnEditRef"
                                data-article-id="<?= (int)$articleId ?>"
                                data-article-ref="<?= htmlspecialchars((string)($article['reference'] ?? ''), ENT_QUOTES) ?>">
                                Modifier
                            </button>
                        <?php endif; ?>
                    </li>
                </ul>


                <?php

                // Séparations utiles
                // On réutilise $alerts (déjà filtrées par target_role) :
                $alertsProblems = array_filter($alerts, fn($a) => ($a['url'] ?? '') === 'problem');
                $alertsMaintenance = array_filter($alerts, fn($a) => ($a['url'] ?? '') === 'maintenance_due');

                // État global pour le badge
                $hasOpenIncidentOrMaint = !empty($alertsProblems) || !empty($alertsMaintenance);
                $etatLabel = $hasOpenIncidentOrMaint ? 'PANNE/ENTRETIEN' : 'OK';
                $etatClass = $hasOpenIncidentOrMaint ? 'bg-danger' : 'bg-success';

                ?>



                <!-- ===== Entretien (compteur/état) ===== -->
                <?php if ($maintenanceMode !== 'none' || $hasHourMeter): ?>
                    <hr>
                    <h6 class="fw-bold mt-3">Entretien</h6>
                    <?php
                    $renderAlertAttachment = function (?string $filePath) {
                        if (!$filePath) return '';
                        $url = '/' . ltrim($filePath, '/');
                        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                            return '<a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener">
                  <img src="' . htmlspecialchars($url) . '" alt="pièce jointe"
                       style="max-width:70px; max-height:70px; border-radius:6px; margin-top:.25rem;"/>
                </a>';
                        }
                        if ($ext === 'pdf') {
                            return '<div class="mt-1"><a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener">Voir le PDF</a></div>';
                        }
                        return '<div class="mt-1"><a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener">Voir le fichier</a></div>';
                    };
                    ?>


                    <?php if ($maintenanceMode === 'hour_meter' || $hasHourMeter): ?>

                        <div>État :
                            <span class="badge <?= $etatClass ?>" data-article-etat-badge><?= $etatLabel ?></span>
                        </div>

                        <?php if (!empty($alertsProblems)): ?>
                            <div class="mt-2 fw-semibold">⚠️ Problèmes ouverts</div>
                            <ul class="list-group list-group-flush mt-1" id="alertsProblemsList">
                                <?php foreach ($alertsProblems as $a): ?>
                                    <?php
                                    $isArchived = !empty($a['archived_at']);
                                    $isUnread   = ((int)($a['is_read'] ?? 0) === 0);
                                    $canResolve = ($isAdmin || $isDepot) && !$isArchived;

                                    ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-start">
                                        <div class="me-3">
                                            <div class="<?= $isUnread && !$isArchived ? 'fw-semibold' : 'text-muted' ?>">
                                                <?= nl2br(htmlspecialchars($a['message'] ?? '')) ?>
                                            </div>
                                            <small class="text-muted"><?= date('d/m/Y H:i', strtotime($a['created_at'])) ?></small>
                                            <?= $renderAlertAttachment($a['alert_file'] ?? null) ?>
                                        </div>

                                        <?php if ($canResolve): ?>
                                            <button class="btn btn-sm btn-success btn-resolve-one" data-alert-id="<?= (int)$a['id'] ?>">
                                                Marquer résolu
                                            </button>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>

                            </ul>
                        <?php else: ?>
                            <small id="alertsProblemsEmpty" class="text-muted d-block mt-2">Aucun problème en cours.</small>
                        <?php endif; ?>

                        <?php if (!empty($alertsMaintenance)): ?>
                            <div class="mt-3 fw-semibold">🛠️ Entretiens à réaliser</div>
                            <ul class="list-group list-group-flush mt-1" id="alertsMaintenanceList">
                                <?php foreach ($alertsMaintenance as $a): ?>
                                    <?php
                                    $isArchived = !empty($a['archived_at']);
                                    $isUnread   = ((int)($a['is_read'] ?? 0) === 0);
                                    $canResolve = ($isAdmin || $isDepot) && !$isArchived;

                                    ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-start">
                                        <div class="me-3">
                                            <div class="<?= $isUnread && !$isArchived ? 'fw-semibold' : 'text-muted' ?>">
                                                <?= nl2br(htmlspecialchars($a['message'] ?? 'Entretien à prévoir')) ?>
                                            </div>
                                            <small class="text-muted"><?= date('d/m/Y H:i', strtotime($a['created_at'])) ?></small>
                                            <?= $renderAlertAttachment($a['alert_file'] ?? null) ?>
                                        </div>

                                        <?php if ($canResolve): ?>
                                            <button class="btn btn-sm btn-outline-success btn-resolve-one" data-alert-id="<?= (int)$a['id'] ?>">
                                                Marquer résolu
                                            </button>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>

                            </ul>
                        <?php endif; ?>

                        <div>Compteur: <strong><?= $lastHours ?> <?= htmlspecialchars($hourUnit) ?></strong></div>


                        <?php if ($isAdmin || $isDepot || $isChef): ?>
                            <form class="mt-2 d-flex gap-2" id="formHour">
                                <input type="hidden" name="action" value="hour_meter">
                                <input type="hidden" name="stock_id" value="<?= (int)$articleId ?>">
                                <?php if ($contextChantier): ?>
                                    <input type="hidden" name="chantier_id" value="<?= (int)$contextChantier ?>">
                                <?php endif; ?>
                                <input type="number" min="0" step="1" class="form-control" name="hours"
                                    value="<?= $lastHours ?>" required style="max-width:200px">
                                <button class="btn btn-outline-primary">Mettre à jour</button>
                            </form>
                            <div id="hourMsg" class="alert d-none mt-2"></div>
                        <?php endif; ?>

                        <?php if (($article['maintenance_mode'] ?? '') === 'hour_meter'): ?>
                            <hr class="my-3">
                            <button id="btnDeclarePanne"
                                class="btn btn-danger w-100"
                                data-bs-toggle="modal"
                                data-bs-target="#modalDeclarePanne"
                                data-article-id="<?= (int)$article['id'] ?>">
                                Déclarer un problème
                            </button>
                        <?php endif; ?>

                        <?php if ($isChef): ?>
                            <?php
                            $chefTodo = 0;
                            try {
                                $stReq = $pdo->prepare("
          SELECT COUNT(*) FROM stock_alerts
          WHERE stock_id = :sid
            AND type = 'incident'
            AND url = 'hour_meter_request'
            AND archived_at IS NULL
        ");
                                $stReq->execute([':sid' => $articleId]);
                                $chefTodo = (int)$stReq->fetchColumn();
                            } catch (Throwable $e) {
                                $chefTodo = 0;
                            }
                            ?>
                            <?php if ($chefTodo > 0): ?>
                                <div class="alert alert-warning d-flex align-items-center gap-2">
                                    <span>⏱️ Relevé d'heures demandé pour cet article. Merci de flasher le QR et de saisir le compteur.</span>
                                    <a class="btn btn-sm btn-outline-dark ms-auto" href="<?= htmlspecialchars($qrPublicUrl) ?>" target="_blank">Ouvrir la page QR</a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                    <?php elseif ($maintenanceMode === 'electrical'): ?>
                        <div>État :
                            <?php
                            // Problèmes ouverts = incidents "problem" non archivés (peu importe "lu")
                            $openProblems = array_filter(
                                $alerts,
                                fn($al) => (($al['type'] ?? '') === 'incident')
                                    && (($al['url']  ?? '') === 'problem')
                                    && empty($al['archived_at'])
                            );
                            $hasOpenProblem = !empty($openProblems);
                            ?>
                            <?php if ($hasOpenProblem): ?>
                                <span class="badge bg-danger">Problème</span>
                            <?php else: ?>
                                <span class="badge bg-success">OK</span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($alerts)): ?>
                            <ul class="list-group list-group-flush mt-2">
                                <?php foreach ($alerts as $a): ?>
                                    <?php
                                    $isArchived = !empty($a['archived_at']);
                                    $isUnread   = ((int)($a['is_read'] ?? 0) === 0);
                                    $canResolve = ($isAdmin || $isDepot) && !$isArchived;

                                    ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-start">
                                        <div class="me-3">
                                            <div class="<?= (!$isArchived && $isUnread) ? 'fw-semibold' : 'text-muted' ?>">
                                                <?= nl2br(htmlspecialchars($a['message'] ?? '')) ?>
                                            </div>
                                            <small class="text-muted"><?= date('d/m/Y H:i', strtotime($a['created_at'])) ?></small>

                                            <!-- miniatures / liens pièces jointes -->
                                            <?= $renderAlertAttachment($a['alert_file'] ?? null) ?>

                                            <?php if ($isArchived): ?>
                                                <span class="badge bg-secondary ms-2">clos</span>
                                            <?php elseif (!$isUnread): ?>
                                                <span class="badge bg-light text-muted border ms-2">lu</span>
                                            <?php endif; ?>
                                        </div>

                                        <?php $canResolve = ($isAdmin || $isDepot) && !$isArchived && $isUnread; ?>
                                        <?php if ($canResolve): ?>
                                            <button class="btn btn-sm btn-success btn-resolve-one" data-alert-id="<?= (int)$a['id'] ?>">
                                                Marquer résolu
                                            </button>
                                        <?php endif; ?>

                                    </li>
                                <?php endforeach; ?>
                            </ul>


                        <?php else: ?>
                            <small class="text-muted d-block mt-2">Aucune alerte enregistrée.</small>
                        <?php endif; ?>

                        <button id="btnDeclarePanne"
                            class="btn btn-danger w-100 mt-3"
                            data-bs-toggle="modal"
                            data-bs-target="#modalDeclarePanne">
                            Déclarer un problème
                        </button>

                        <div id="resolveMsg" class="alert d-none mt-2"></div>
                    <?php endif; ?>

                <?php endif; ?>

                <!-- Modale -->
                <div class="modal fade" id="modalDeclarePanne" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Déclarer un problème</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                            </div>

                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">Description (obligatoire)</label>
                                    <textarea id="panneComment"
                                        class="form-control"
                                        rows="4"
                                        placeholder="Décrire le symptôme, contexte, etc."
                                        required></textarea>
                                </div>
                                <?php if (!empty($contextChantier)): ?>
                                    <input type="hidden" id="panneChantierId" value="<?= (int)$contextChantier ?>">
                                <?php endif; ?>

                                <?php if (($article['maintenance_mode'] ?? '') === 'hour_meter'): ?>
                                    <!-- Champ compteur visible UNIQUEMENT pour les articles en mode compteur d'heures -->
                                    <div class="mb-2">
                                        <label class="form-label">Compteur (h) — optionnel</label>
                                        <input id="panneHours" type="number" min="0" step="1"
                                            class="form-control"
                                            placeholder="ex : <?= (int)($lastHours ?? 0) ?>">
                                        <div class="form-text">Si renseigné, le compteur sera mis à jour et historisé.</div>
                                    </div>
                                <?php endif; ?>

                                <div class="mb-3">
                                    <label class="form-label">Pièce jointe (photo/PDF, optionnel)</label>
                                    <input id="panneFile" type="file" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf">
                                    <div class="form-text">Formats acceptés : JPG, PNG, WEBP, PDF</div>
                                </div>
                            </div>

                            <div class="modal-footer">
                                <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                                <button id="confirmDeclarePanne" type="button" class="btn btn-danger">Envoyer l’alerte</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal: Éditer la référence -->
                <div class="modal fade" id="modalEditRef" tabindex="-1" aria-labelledby="modalEditRefLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <form class="modal-content" id="formEditRef">
                            <div class="modal-header">
                                <h5 class="modal-title" id="modalEditRefLabel">Modifier la référence</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" name="article_id" id="ref_article_id" value="">
                                <div class="mb-3">
                                    <label for="ref_value" class="form-label">Référence</label>
                                    <input type="text" class="form-control" name="reference" id="ref_value" maxlength="100" required>
                                    <div class="form-text">100 caractères max.</div>
                                </div>
                                <!-- si tu as un token CSRF, ajoute-le ici -->
                                <!-- <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?? '' ?>"> -->
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
                                <button type="submit" class="btn btn-primary">Enregistrer</button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>
        </div>

        <!-- Fichiers -->
        <div class="tab-pane fade" id="files" role="tabpanel">
            <div class="card shadow-sm p-3">
                <h5 class="fw-bold mb-3">Documents techniques</h5>

                <?php if (empty($articleFiles)): ?>
                    <div class="alert alert-light border d-flex align-items-center mb-0">
                        <span class="me-2">📁</span> Aucun fichier associé à cet article.
                    </div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($articleFiles as $f):
                            $icon = file_icon_from_mime($f['type_mime'] ?? null);
                            $name = $f['nom_affichage'] ?: basename($f['chemin_fichier']);
                            $url  = '/' . ltrim($f['chemin_fichier'] ?? '', '/');
                            $size = human_filesize(isset($f['taille']) ? (int)$f['taille'] : null);
                            $date = !empty($f['created_at']) ? date('d/m/Y H:i', strtotime($f['created_at'])) : '-';
                        ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div class="d-flex align-items-center gap-3">
                                    <span style="font-size:1.2rem"><?= $icon ?></span>
                                    <div class="d-flex flex-column">
                                        <a href="<?= htmlspecialchars($url) ?>" target="_blank" class="fw-semibold text-break">
                                            <?= htmlspecialchars($name) ?>
                                        </a>
                                        <small class="text-muted">
                                            <?= htmlspecialchars($f['type_mime'] ?? 'application/octet-stream') ?> · <?= $size ?> · ajouté le <?= $date ?>
                                        </small>
                                    </div>
                                </div>
                                <div>
                                    <a href="<?= htmlspecialchars($url) ?>" download class="btn btn-sm btn-outline-primary">Télécharger</a>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Historique -->
        <div class="tab-pane fade" id="history" role="tabpanel">
            <div class="card shadow-sm p-3">
                <h5 class="fw-bold mb-3">Historique des transferts</h5>

                <?php if (empty($mouvements)): ?>
                    <div class="alert alert-light border d-flex align-items-center mb-0">
                        <span class="me-2">🕘</span> Aucun mouvement enregistré pour cet article.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle history-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>De</th>
                                    <th>Vers</th>
                                    <th class="text-end">Qté</th>
                                    <th>Statut</th>
                                    <th>Par</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($mouvements as $mv):
                                    $date = date('d/m/Y H:i', strtotime($mv['created_at']));
                                    $from = label_personne_lieu($mv['source_type'] ?? null, $mv, 'source');
                                    $to   = label_personne_lieu($mv['dest_type']   ?? null, $mv, 'dest');
                                    $qte  = (int)$mv['quantite'];
                                    $by   = label_validateur($mv);
                                ?>
                                    <tr>
                                        <td class="text-nowrap" data-label="Date"><?= $date ?></td>
                                        <td data-label="De"><?= htmlspecialchars($from) ?></td>
                                        <td data-label="Vers"><?= htmlspecialchars($to) ?></td>
                                        <td class="text-end fw-bold text-nowrap" data-label="Qté"><?= $qte ?></td>
                                        <td class="text-nowrap" data-label="Statut"><?= badge_statut($mv['statut']) ?></td>
                                        <td class="text-nowrap" data-label="Par"><?= $by ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php
                        $hasPrev = $page > 1;
                        $hasNext = ($offset + count($mouvements)) < $totalRows;
                        if ($hasPrev || $hasNext): ?>
                            <nav class="d-flex justify-content-center mt-2">
                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item <?= $hasPrev ? '' : 'disabled' ?>">
                                        <a class="page-link" href="<?= $hasPrev ? historyUrl($page - 1) : '#' ?>" aria-label="Précédent">
                                            &laquo; Précédent
                                        </a>
                                    </li>
                                    <li class="page-item disabled">
                                        <span class="page-link">Page <?= $page ?> / <?= max(1, (int)ceil($totalRows / $perPage)) ?></span>
                                    </li>
                                    <li class="page-item <?= $hasNext ? '' : 'disabled' ?>">
                                        <a class="page-link" href="<?= $hasNext ? historyUrl($page + 1) : '#' ?>" aria-label="Suivant">
                                            Suivant &raquo;
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <!-- Onglet "Historique des états" -->
        <?php if ($showEtatTab): ?>
            <div class="tab-pane fade" id="etatlog" role="tabpanel">
                <?php
                /* =========================================================
       Onglet "Historique des états" — bloc complet avec couleurs
    ========================================================= */

                // Récup de l’historique des états + auteur (created_by)
                $rows = [];
                try {
                    $sql = "
          SELECT ae.id, ae.created_at, ae.action, ae.valeur_int, ae.commentaire, ae.fichier, ae.created_by,
                 ae.alert_id, ae.chantier_id,
                 u.nom  AS auteur_nom, u.prenom AS auteur_prenom,
                 c.nom  AS chantier_nom
          FROM article_etats ae
          LEFT JOIN utilisateurs u ON u.id = ae.created_by
          LEFT JOIN chantiers   c ON c.id = ae.chantier_id
          WHERE ae.article_id = :aid
          " . ($ENT_ID ? " AND ae.entreprise_id = :eid " : "") . "
          ORDER BY ae.id DESC, ae.created_at DESC
        ";
                    $st = $pdo->prepare($sql);
                    $params = [':aid' => $articleId];
                    if ($ENT_ID) $params[':eid'] = $ENT_ID;
                    $st->execute($params);
                    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {
                    $rows = [];
                }

                // helpers
                $fullName = fn(?string $nom, ?string $prenom): string => (trim((string)$nom . $prenom) === '') ? '—' : htmlspecialchars(trim($prenom . ' ' . $nom));

                $renderActionBadge = function (string $action): string {
                    return match ($action) {
                        'declarer_panne' => '<span class="badge bg-danger">panne déclarée</span>',
                        'declarer_ok'    => '<span class="badge bg-success">panne résolue</span>',
                        'compteur_maj'   => '<span class="badge bg-secondary">relevé compteur</span>',
                        default          => '<span class="badge bg-secondary">' . htmlspecialchars($action) . '</span>',
                    };
                };

                $renderValue = fn($action, $val) => (is_numeric($val) && in_array($action, ['compteur_maj', 'declarer_panne', 'declarer_ok'], true))
                    ? htmlspecialchars((string)$val) . ' h'
                    : '—';

                $renderPhoto = function (?string $filePath) {
                    if (!$filePath) return '—';
                    $url = '/' . ltrim($filePath, '/');
                    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                        return '<a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener">
                      <img src="' . htmlspecialchars($url) . '" alt="pièce jointe"
                           style="max-width:70px; max-height:70px; border-radius:6px;"/>
                    </a>';
                    }
                    if ($ext === 'pdf') return '<a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener">Voir le PDF</a>';
                    return '<a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener">Voir le fichier</a>';
                };
                ?>

                <div class="card mt-3">
                    <div class="card-body">
                        <h5 class="card-title mb-3">Historique des états</h5>

                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th style="width:100px;">N°</th>
                                        <th style="width:160px;">Date</th>
                                        <th style="width:150px;">Action</th>
                                        <th style="width:190px;">Envoyé par</th>
                                        <th style="width:190px;">Chantier</th>
                                        <th style="width:220px;">Réparation validée par</th>
                                        <th style="width:110px;">Valeur</th>
                                        <th>Commentaire</th>
                                        <th style="width:110px;">Photos</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($rows)): ?>
                                        <tr>
                                            <td colspan="9" class="text-muted">Aucun évènement enregistré.</td>
                                        </tr>
                                        <?php else: foreach ($rows as $r):
                                            $num     = !empty($r['alert_id']) ? '#' . (int)$r['alert_id'] : '—';
                                            $action  = (string)$r['action'];
                                            $chantier = trim((string)($r['chantier_nom'] ?? '')) !== '' ? htmlspecialchars((string)$r['chantier_nom']) : '—';
                                            $val = (is_numeric($r['valeur_int']) && in_array($action, ['compteur_maj', 'declarer_panne', 'declarer_ok'], true))
                                                ? htmlspecialchars((string)$r['valeur_int']) . ' h' : '—';
                                            if ($action === 'declarer_ok' && !empty($r['alert_id'])) {
                                                $com = 'problème #' . (int)$r['alert_id'] . ' résolu';
                                            } else {
                                                $com = trim((string)$r['commentaire']) !== '' ? htmlspecialchars((string)$r['commentaire']) : '—';
                                            }
                                            $who   = $fullName($r['auteur_nom'] ?? '', $r['auteur_prenom'] ?? '');
                                            $photo = $renderPhoto($r['fichier'] ?? null);
                                            $envoyePar = '—';
                                            $reparePar = '—';
                                            if ($action === 'declarer_panne') {
                                                $envoyePar = ($who !== '—') ? $who : '<span class="text-muted">QR public</span>';
                                            } elseif ($action === 'declarer_ok') {
                                                $reparePar = ($who !== '—') ? $who : '<span class="text-muted">—</span>';
                                            }
                                            $rowClass = match ($action) {
                                                'declarer_panne' => 'table-danger',
                                                'declarer_ok'    => 'table-success',
                                                'compteur_maj'   => 'table-secondary',
                                                default          => ''
                                            };
                                        ?>
                                            <tr class="<?= $rowClass ?>">
                                                <td><?= $num ?></td>
                                                <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)($r['created_at'] ?? 'now')))) ?></td>
                                                <td><?= $renderActionBadge($action) ?></td>
                                                <td><?= $envoyePar ?></td>
                                                <td><?= $chantier ?></td>
                                                <td><?= $reparePar ?></td>
                                                <td><?= $val ?></td>
                                                <td><?= $com ?></td>
                                                <td><?= $photo ?></td>
                                            </tr>
                                    <?php endforeach;
                                    endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>


    </div>
</div>


<script>
    document.addEventListener('DOMContentLoaded', () => {
        const editBtn = document.getElementById('btnEditRef');
        if (editBtn) {
            editBtn.addEventListener('click', () => {
                document.getElementById('ref_article_id').value = editBtn.getAttribute('data-article-id');
                document.getElementById('ref_value').value = editBtn.getAttribute('data-article-ref') || '';
                new bootstrap.Modal(document.getElementById('modalEditRef')).show();
            });
        }

        const form = document.getElementById('formEditRef');
        if (form) {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const fd = new FormData(form);

                try {
                    const resp = await fetch('/stock/ajax/article_update_reference.php', {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: fd
                    });
                    const data = await resp.json();

                    if (!resp.ok || !data.ok) throw new Error(data.msg || 'Erreur serveur');

                    const newRef = data.reference || '-';
                    const refSpan = document.getElementById('articleRefValue');
                    refSpan.textContent = newRef;

                    // feedback visuel
                    refSpan.classList.add('bg-warning-subtle', 'px-1', 'rounded');
                    setTimeout(() => refSpan.classList.remove('bg-warning-subtle', 'px-1', 'rounded'), 1200);

                    // maj attribut du bouton
                    if (editBtn) editBtn.setAttribute('data-article-ref', newRef);

                    bootstrap.Modal.getInstance(document.getElementById('modalEditRef')).hide();
                } catch (err) {
                    alert(err.message || 'Impossible de mettre à jour la référence.');
                }
            });
        }
    });
</script>


<script>
    document.getElementById('confirmDeclarePanne').addEventListener('click', async function() {
        const btn = this;
        const comment = document.getElementById('panneComment').value.trim();
        const fileEl = document.getElementById('panneFile');
        const hoursEl = document.getElementById('panneHours');
        const stockId = document.getElementById('panneStockId').value;
        const chantierIdEl = document.getElementById('panneChantierId');

        if (!comment) {
            alert("Merci d'indiquer une description.");
            return;
        }

        const fd = new FormData();
        fd.append('action', 'declarer_panne');
        fd.append('stock_id', stockId);
        fd.append('commentaire', comment);
        if (hoursEl && hoursEl.value !== '') {
            fd.append('hours', parseInt(hoursEl.value, 10));
        }
        if (chantierIdEl) {
            fd.append('chantier_id', chantierIdEl.value);
        }
        if (fileEl && fileEl.files && fileEl.files[0]) {
            fd.append('fichier', fileEl.files[0]);
        }

        btn.disabled = true;

        try {
            const resp = await fetch('/stock/ajax/ajax_article_etat_save.php', {
                method: 'POST',
                body: fd
            });
            const data = await resp.json();

            if (!resp.ok || !data.ok) {
                throw new Error(data.msg || 'Erreur serveur');
            }

            // succès : on ferme la modale et on rafraîchit proprement
            const modalEl = document.getElementById('modalDeclarePanne');
            const bsModal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            bsModal.hide();

            // Option : reload pour voir l’historique mis à jour (photos + ligne rouge)
            location.reload();

        } catch (e) {
            alert('Échec: ' + e.message);
        } finally {
            btn.disabled = false;
        }
    });
</script>

<script>
    window.IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
</script>

<script>
    // === Helpers front : texte -> JSON (UrlEncoded)
    async function postUrlEncoded(data) {
        const res = await fetch('/stock/ajax/ajax_article_etat_save.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams(data),
            credentials: 'same-origin'
        });
        const raw = await res.text();
        let json;
        try {
            json = JSON.parse(raw);
        } catch {
            throw new Error('Réponse non-JSON du serveur:\n' + raw);
        }
        if (!res.ok || json.ok === false) throw new Error(json.msg || json.error || 'Erreur serveur');
        return json;
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (location.hash === '#history') {
            const t = document.querySelector('[data-bs-target="#history"]');
            if (t) new bootstrap.Tab(t).show();
        }

        // Compteur (connecté)
        const fHour = document.getElementById('formHour');
        const hourMsg = document.getElementById('hourMsg');
        if (fHour) {
            fHour.addEventListener('submit', async (e) => {
                e.preventDefault();
                const data = Object.fromEntries(new FormData(fHour).entries());
                const btn = fHour.querySelector('button');
                btn.disabled = true;
                try {
                    await postUrlEncoded(data);
                    location.reload();
                } catch (err) {
                    if (hourMsg) {
                        hourMsg.className = 'alert alert-danger mt-2';
                        hourMsg.textContent = err.message;
                        hourMsg.classList.remove('d-none');
                    } else {
                        alert(err.message);
                    }
                } finally {
                    btn.disabled = false;
                }
            });
        }

        // Déclarer un problème (connecté) : FormData (upload)
        const form = document.getElementById('formDeclare');
        const fb = document.getElementById('declareFeedback');
        if (form) {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                fb.className = 'small';
                fb.textContent = '';
                const fd = new FormData(form); // article_id, action, commentaire, fichier
                try {
                    const res = await fetch('/stock/ajax/ajax_article_etat_save.php', {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin'
                    });
                    const raw = await res.text();
                    const json = JSON.parse(raw);
                    if (!res.ok || json.ok === false) throw new Error(json.msg || 'Erreur serveur');

                    // ✅ MAJ UI SANS RECHARGER
                    const badge = document.querySelector('[data-article-etat-badge]');
                    if (badge) {
                        badge.className = 'badge bg-danger';
                        badge.textContent = 'PANNE';
                    }

                    // Liste UL existante ou à créer
                    let list = document.querySelector('#alertsProblemsList');
                    if (!list) {
                        // Si le message “Aucune alerte…” est là, on le retire
                        const empty = document.querySelector('#alertsProblemsEmpty');
                        if (empty) empty.remove();

                        list = document.createElement('ul');
                        list.className = 'list-group list-group-flush mt-2';
                        list.id = 'alertsProblemsList';
                        const anchor = document.querySelector('[data-article-etat-badge]');
                        anchor && anchor.closest('div').insertAdjacentElement('afterend', list);
                    }

                    // Ajoute l’élément en tête
                    const a = json.alert;
                    const li = document.createElement('li');
                    li.className = 'list-group-item d-flex justify-content-between align-items-start';
                    li.innerHTML = `
    <div class="me-3">
      <div class="fw-semibold">${(a.message || '').replace(/</g,'&lt;').replace(/\n/g,'<br>')}</div>
      <small class="text-muted">${new Date(a.created_at.replace(' ','T')).toLocaleString()}</small>
    </div>
    ${ (window.IS_ADMIN ? `<button class="btn btn-sm btn-success btn-resolve-one" data-alert-id="${a.id}">Marquer résolu</button>` : '') }
  `;
                    list.prepend(li);

                    // Feedback modal
                    fb.className = 'alert alert-success';
                    fb.textContent = 'Problème envoyé.';
                    // Fermer la modale un peu après
                    setTimeout(() => bootstrap.Modal.getInstance(document.getElementById('declareModal'))?.hide(), 400);
                } catch (err) {
                    fb.className = 'alert alert-danger';
                    fb.textContent = String(err.message || err);
                }

            });
        }

        // Résolution d'une seule alerte
        document.addEventListener('click', async (e) => {
            const btn = e.target.closest('.btn-resolve-one');
            if (!btn) return;
            e.preventDefault();
            btn.disabled = true;
            try {
                await postUrlEncoded({
                    action: 'resolve_one',
                    stock_id: '<?= (int)$articleId ?>',
                    alert_id: btn.dataset.alertId
                });
                location.reload();
            } catch (err) {
                const msg = document.getElementById('resolveMsg');
                if (msg) {
                    msg.className = 'alert alert-danger mt-2';
                    msg.textContent = err.message;
                    msg.classList.remove('d-none');
                } else {
                    alert(err.message);
                }
            } finally {
                btn.disabled = false;
            }
        });
    });
</script>

<?php if ($isAdmin && !empty($article['qr_token'])): ?>
    <style>
        @media print {
            body * {
                visibility: hidden;
            }

            .printable,
            .printable * {
                visibility: visible;
            }

            .printable {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                padding: 15mm;
            }

            .qr-card {
                display: inline-block;
                width: 60mm;
                margin: 5mm;
                text-align: center;
            }

            .qr-card img {
                max-width: 100%;
                height: auto;
            }

            .qr-card .title {
                font-size: 12pt;
                margin-top: 4mm;
            }
        }
    </style>
    <div class="printable d-none d-print-block">
        <div class="qr-card">
            <img src="/stock/qr_render.php?t=<?= urlencode($article['qr_token']) ?>&v=<?= time() ?>" alt="QR">
            <div class="title"><?= htmlspecialchars($article['nom'] ?? 'Article') ?></div>
            <div class="title">Réf. <?= htmlspecialchars($article['reference'] ?? '—') ?></div>
        </div>
    </div>
<?php endif; ?>
<script>
    window.ARTICLE_ID = <?= (int)$article['id'] ?>;
</script>
<script src="/stock/js/articleEtat.js"></script>


<?php require_once __DIR__ . '/../templates/footer.php'; ?>