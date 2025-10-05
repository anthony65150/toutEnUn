<?php
// Fichier: /stock/article.php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';

// --- Mode QR public ? ---
$qrToken = isset($_GET['t']) ? (string)$_GET['t'] : '';
$qrToken = (strlen($qrToken) >= 16 && strlen($qrToken) <= 64) ? $qrToken : '';
$isQrView = ($qrToken !== '');

// Si pas connecté ET pas en mode QR public => connexion
if (!$isQrView && !isset($_SESSION['utilisateurs'])) {
    header("Location: ../connexion.php");
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
        SELECT c.nom AS chantier_nom, sc.quantite
        FROM stock_chantiers sc
        JOIN chantiers c ON c.id = sc.chantier_id
        WHERE sc.stock_id = :sid AND sc.quantite > 0
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
        SELECT c.nom AS chantier_nom, sc.quantite
        FROM stock_chantiers sc
        JOIN chantiers c ON c.id = sc.chantier_id
        WHERE sc.stock_id = ? AND sc.quantite > 0
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

/* ================================
   Entretien / incidents (nouveau)
================================ */
$maintenanceMode = $article['maintenance_mode'] ?? 'none';
$qrTokenForPost  = (string)($article['qr_token'] ?? '');

/* Toutes les alertes pour cet article (y compris archivées) */
$alerts = [];
try {
    $q = $pdo->prepare("
        SELECT a.id,
               a.message,
               a.is_read,
               a.created_at,
               a.archived_at,         -- << NEW: on récupère l’archivage
               a.archived_by          -- << NEW: si tu veux afficher qui a archivé
        FROM stock_alerts a
        JOIN stock s ON s.id = a.stock_id
        WHERE a.stock_id = :sid
          " . ($ENT_ID ? " AND s.entreprise_id = :eid " : "") . "
        ORDER BY
          (a.archived_at IS NULL) DESC,   -- d’abord non archivées
          a.is_read ASC,                  -- non lues puis lues
          a.created_at DESC, a.id DESC
    ");
    $params = [':sid' => $articleId];
    if ($ENT_ID) $params[':eid'] = $ENT_ID;
    $q->execute($params);
    $alerts = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $alerts = [];
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
            <div class="card-body d-flex gap-3 flex-wrap">
                <div><span class="text-muted">Total :</span> <strong><?= $qTot ?></strong></div>
                <div><span class="text-muted">Chantiers :</span> <strong><?= $qCh ?></strong></div>
                <div><span class="text-muted">Dépôts :</span> <strong><?= $qDepot ?></strong></div>
            </div>
        </div>

        <?php if ($maintenanceMode === 'hour_meter'): ?>
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="mb-2">Relevé compteur (heures)</h5>
                    <form class="row g-2" id="formHourQR">
                        <input type="hidden" name="action" value="hour_meter">
                        <input type="hidden" name="stock_id" value="<?= (int)$articleId ?>">
                        <div class="col-auto"><input type="number" min="0" step="1" class="form-control" name="hours" value="<?= (int)($article['compteur_heures'] ?? 0) ?>"></div>
                        <div class="col-auto"><button class="btn btn-primary">Enregistrer</button></div>
                    </form>
                    <small class="text-muted d-block mt-2">Dernier relevé: <?= (int)($article['compteur_heures'] ?? 0) ?> h</small>
                    <div id="declareMsgQR" class="alert d-none mt-2"></div>
                </div>
            </div>
        <?php elseif ($maintenanceMode === 'electrical'): ?>
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="mb-2">Statut</h5>
                    <div>
                        État :
                        <?php
                        // << NEW: un “problème” signifie une alerte NON ARCHIVÉE et NON LUE
                        $hasOpen = false;
                        foreach ($alerts as $a) {
                            if (empty($a['archived_at']) && (int)$a['is_read'] === 0) {
                                $hasOpen = true;
                                break;
                            }
                        }
                        ?>
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
                                $isUnread   = ((int)$a['is_read'] === 0);
                                ?>
                                <li class="list-group-item d-flex justify-content-between align-items-start">
                                    <div class="me-3">
                                        <div class="<?= $isUnread && !$isArchived ? 'fw-semibold' : 'text-muted' ?>">
                                            <?= nl2br(htmlspecialchars($a['message'])) ?>
                                        </div>
                                        <small class="text-muted">
                                            <?= date('d/m/Y H:i', strtotime($a['created_at'])) ?>
                                        </small>
                                        <?php if ($isArchived): ?>
                                            <span class="badge bg-secondary ms-2">archivée</span>
                                        <?php elseif (!$isUnread): ?>
                                            <span class="badge bg-secondary ms-2">clos</span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($isAdmin && $isUnread && !$isArchived): ?>
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
                    <div id="resolveMsg" class="alert d-none mt-2"></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="d-grid gap-2">
            <a class="btn btn-outline-secondary" href="/connexion.php">Se connecter</a>
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

        (function() {
            const fh = document.getElementById('formHourQR');
            const boxQR = document.getElementById('declareMsgQR');
            if (fh) {
                fh.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    const data = Object.fromEntries(new FormData(fh).entries());
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

            const btnDeclareQR = document.getElementById('btnSendDeclareQR');
            if (btnDeclareQR) {
                btnDeclareQR.addEventListener('click', async () => {
                    const f = document.getElementById('formDeclareQR');
                    const data = Object.fromEntries(new FormData(f).entries());
                    const box = document.getElementById('declareMsg');
                    btnDeclareQR.disabled = true;
                    try {
                        await postUrlEncoded(data);
                        if (box) {
                            box.className = 'alert alert-success small';
                            box.textContent = 'Problème envoyé. Merci.';
                            box.classList.remove('d-none');
                        }
                        setTimeout(() => location.reload(), 800);
                    } catch (err) {
                        if (box) {
                            box.className = 'alert alert-danger small';
                            box.textContent = err.message;
                            box.classList.remove('d-none');
                        } else {
                            alert(err.message);
                        }
                    } finally {
                        btnDeclareQR.disabled = false;
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
    <div class="row g-4 mb-4">
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

        <div class="col-md-8">
            <div class="card shadow-sm p-3">
                <h5 class="fw-bold mb-3">Quantités disponibles</h5>
                <div class="row text-center">
                    <div class="col">
                        <div class="bg-light p-3 rounded-2">
                            <div class="fw-bold fs-4"><?= $totalQuantite ?></div>
                            <div class="text-muted">Total</div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="bg-light p-3 rounded-2">
                            <div class="fw-bold fs-4"><?= $quantiteChantier ?></div>
                            <div class="text-muted">Sur chantiers</div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="bg-light p-3 rounded-2">
                            <div class="fw-bold fs-4"><?= $quantiteDepot ?></div>
                            <div class="text-muted">En dépôt</div>
                        </div>
                    </div>
                </div>
                <?php if ($currentQtyContext !== null): ?>
                    <?php $alertClass = $currentQtyContext > 0 ? 'alert-success' : 'alert-danger'; ?>
                    <div class="alert <?= $alertClass ?> mt-3 mb-0 py-2">
                        <?= htmlspecialchars($currentLabelContext) ?> : <strong><?= (int)$currentQtyContext ?></strong>
                    </div>
                <?php endif; ?>
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
            <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab">Historique</button>
        </li>
    </ul>

    <div class="tab-content" id="articleTabContent">
        <!-- Détails -->
        <div class="tab-pane fade show active" id="details" role="tabpanel">
            <div class="card shadow-sm p-3">
                <h5 class="fw-bold">Caractéristiques de l'article</h5>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item">Référence : <?= htmlspecialchars($article['reference'] ?? '-') ?></li>
                    <li class="list-group-item">Dimensions : <?= htmlspecialchars($article['dimensions'] ?? '-') ?></li>
                    <li class="list-group-item">Poids : <?= htmlspecialchars($article['poids'] ?? '-') ?></li>
                    <li class="list-group-item">Matériau : <?= htmlspecialchars($article['materiau'] ?? '-') ?></li>
                    <li class="list-group-item">Fournisseur : <?= htmlspecialchars($article['fournisseur'] ?? '-') ?></li>
                </ul>

                <!-- ===== Entretien (nouveau) ===== -->
                <?php if ($maintenanceMode !== 'none'): ?>
                    <hr>
                    <h6 class="fw-bold mt-3">Entretien</h6>

                    <?php if ($maintenanceMode === 'hour_meter'): ?>
                        <div>Compteur: <strong><?= (int)($article['compteur_heures'] ?? 0) ?> h</strong></div>
                        <?php if ($isAdmin || $isDepot): ?>
                            <form class="mt-2 d-flex gap-2" id="formHour">
                                <input type="hidden" name="action" value="hour_meter">
                                <input type="hidden" name="stock_id" value="<?= (int)$articleId ?>">
                                <input type="number" min="0" step="1" class="form-control" name="hours" value="<?= (int)($article['compteur_heures'] ?? 0) ?>" required style="max-width:200px">
                                <button class="btn btn-outline-primary">Mettre à jour</button>
                            </form>
                            <div id="hourMsg" class="alert d-none mt-2"></div>
                        <?php endif; ?>

                    <?php elseif ($maintenanceMode === 'electrical'): ?>
                        <div>État :
                            <?php
                            $hasOpen = false;
                            foreach ($alerts as $a) {
                                if ((int)$a['is_read'] === 0) {
                                    $hasOpen = true;
                                    break;
                                }
                            }
                            ?>
                            <?php if ($hasOpen): ?>
                                <span class="badge bg-danger">Problème</span>
                            <?php else: ?>
                                <span class="badge bg-success">OK</span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($alerts)): ?>
                            <ul class="list-group list-group-flush mt-2">
                                <?php foreach ($alerts as $a): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-start">
                                        <div class="me-3">
                                            <div class="<?= (int)$a['is_read'] === 0 ? 'fw-semibold' : 'text-muted' ?>">
                                                <?= nl2br(htmlspecialchars($a['message'])) ?>
                                            </div>
                                            <small class="text-muted"><?= date('d/m/Y H:i', strtotime($a['created_at'])) ?></small>
                                            <?php if ((int)$a['is_read'] !== 0): ?>
                                                <span class="badge bg-secondary ms-2">clos</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($isAdmin && (int)$a['is_read'] === 0): ?>
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
                        <div id="resolveMsg" class="alert d-none mt-2"></div>
                    <?php endif; ?>
                <?php endif; ?>
                <!-- ===== /Entretien ===== -->

                <hr>
                <h6 class="fw-bold mt-3">Répartition par chantier</h6>
                <ul class="list-group list-group-flush">
                    <?php if (!empty($quantitesParChantier)): ?>
                        <?php foreach ($quantitesParChantier as $chantier): ?>
                            <li class="list-group-item">
                                <?= htmlspecialchars($chantier['chantier_nom']) ?> :
                                <strong><?= (int)$chantier['quantite'] ?></strong> unité(s)
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="list-group-item text-muted">Aucune quantité sur les chantiers</li>
                    <?php endif; ?>
                </ul>
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
    </div>
</div>

<!-- Modale déclaration (connecté) -->
<div class="modal fade" id="declareModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="formDeclare" enctype="multipart/form-data">
            <div class="modal-header">
                <h5 class="modal-title">Déclarer un problème</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>

            <div class="modal-body">
                <input type="hidden" name="article_id" value="<?= (int)$article['id'] ?>">
                <input type="hidden" name="action" value="declarer_panne">

                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" name="commentaire" rows="4" required
                        placeholder="Décrivez brièvement le souci (ex. : fuite, problème electrique…)"></textarea>
                </div>


                <div id="declareFeedback" class="small"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Fermer</button>
                <button type="submit" class="btn btn-primary">Envoyer</button>
            </div>
        </form>
    </div>
</div>

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
                    let json;
                    try {
                        json = JSON.parse(raw);
                    } catch {
                        throw new Error('Réponse non-JSON du serveur:\n' + raw);
                    }
                    if (!res.ok || json.ok === false) throw new Error(json.msg || 'Erreur serveur');
                    fb.className = 'alert alert-success';
                    fb.textContent = 'Problème envoyé.';
                    setTimeout(() => location.reload(), 600);
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

<?php require_once __DIR__ . '/../templates/footer.php'; ?>