<?php
require_once "./config/init.php";

if (!isset($_SESSION['utilisateurs'])) {
    header("Location: connexion.php");
    exit;
}

require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/templates/navigation/navigation.php';

/* ================================
   Params + rôle + sécurités
================================ */
if (!isset($_GET['id'])) {
    echo "<div class='container mt-4 alert alert-danger'>Aucun article sélectionné.</div>";
    require_once __DIR__ . '/templates/footer.php';
    exit;
}
$articleId  = (int) $_GET['id'];
$chantierId = (int) ($_GET['chantier_id'] ?? 0);
$depotId    = (int) ($_GET['depot_id'] ?? 0);

$fonction = $_SESSION['utilisateurs']['fonction'] ?? null;
$userId   = (int)($_SESSION['utilisateurs']['id'] ?? 0);

/* Chef : ne voir que ses chantiers si chantier_id fourni */
if ($fonction === 'chef' && $chantierId > 0) {
    $stmt = $pdo->prepare("SELECT 1 FROM utilisateur_chantiers WHERE utilisateur_id = ? AND chantier_id = ? LIMIT 1");
    $stmt->execute([$userId, $chantierId]);
    if (!$stmt->fetchColumn()) {
        echo "<div class='container mt-4 alert alert-danger'>Accès refusé à ce chantier.</div>";
        require_once __DIR__ . '/templates/footer.php';
        exit;
    }
}
/* Dépôt : ne voir que son dépôt si depot_id fourni */
if ($fonction === 'depot' && $depotId > 0) {
    $stmt = $pdo->prepare("SELECT 1 FROM depots WHERE id = ? AND responsable_id = ? LIMIT 1");
    $stmt->execute([$depotId, $userId]);
    if (!$stmt->fetchColumn()) {
        echo "<div class='container mt-4 alert alert-danger'>Accès refusé à ce dépôt.</div>";
        require_once __DIR__ . '/templates/footer.php';
        exit;
    }
}

/* ================================
   1) ARTICLE
================================ */
$stmt = $pdo->prepare("SELECT * FROM stock WHERE id = ?");
$stmt->execute([$articleId]);
$article = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$article) {
    echo "<div class='container mt-4 alert alert-warning'>Article introuvable.</div>";
    require_once __DIR__ . '/templates/footer.php';
    exit;
}

/* ================================
   2) QUANTITÉS (globales + contexte)
================================ */
$stmt = $pdo->prepare("SELECT COALESCE(SUM(quantite),0) FROM stock_depots WHERE stock_id = ?");
$stmt->execute([$articleId]);
$quantiteDepot = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(quantite),0) FROM stock_chantiers WHERE stock_id = ?");
$stmt->execute([$articleId]);
$quantiteChantier = (int) $stmt->fetchColumn();

$totalQuantite = $quantiteDepot + $quantiteChantier;

/* Quantité du contexte (pour bandeau informatif, sans filtrer l'historique) */
$currentQtyContext   = null;
$currentLabelContext = null;

if ($chantierId > 0) {
    $stmt = $pdo->prepare("SELECT COALESCE(quantite,0) FROM stock_chantiers WHERE stock_id = ? AND chantier_id = ?");
    $stmt->execute([$articleId, $chantierId]);
    $currentQtyContext   = (int)$stmt->fetchColumn();
    $currentLabelContext = "Quantité sur ce chantier";
}
if ($depotId > 0) {
    $stmt = $pdo->prepare("SELECT COALESCE(quantite,0) FROM stock_depots WHERE stock_id = ? AND depot_id = ?");
    $stmt->execute([$articleId, $depotId]);
    $currentQtyContext   = (int)$stmt->fetchColumn();
    $currentLabelContext = "Quantité dans ce dépôt";
}

/* ================================
   3) RÉPARTITION PAR CHANTIER (>0)
================================ */
$stmt = $pdo->prepare("
    SELECT c.nom AS chantier_nom, sc.quantite
    FROM stock_chantiers sc
    JOIN chantiers c ON c.id = sc.chantier_id
    WHERE sc.stock_id = ? AND sc.quantite > 0
    ORDER BY c.nom ASC
");
$stmt->execute([$articleId]);
$quantitesParChantier = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================================
   4) DOCUMENTS LIÉS (stock_documents)
================================ */
$stmt = $pdo->prepare("
    SELECT id, nom_affichage, chemin_fichier, type_mime, taille, created_at
    FROM stock_documents
    WHERE stock_id = ?
    ORDER BY created_at DESC, id DESC
");
$stmt->execute([$articleId]);
$articleFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Helpers documents */
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
   5) HISTORIQUE (stock_mouvements)
   Afficher TOUT l'historique (admin / chef / dépôt)
================================ */
$mouvements = [];
try {
    $sql = "
        SELECT sm.*,
               us.nom AS user_nom, us.prenom AS user_prenom,
               cs.nom AS source_chantier_nom,
               cd.nom AS dest_chantier_nom
        FROM stock_mouvements sm
        LEFT JOIN utilisateurs us ON us.id = sm.utilisateur_id
        LEFT JOIN chantiers cs ON (sm.source_type='chantier' AND cs.id = sm.source_id)
        LEFT JOIN chantiers cd ON (sm.dest_type='chantier' AND cd.id = sm.dest_id)
        WHERE sm.stock_id = :sid
        ORDER BY sm.created_at DESC, sm.id DESC
        LIMIT 200
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['sid' => $articleId]);
    $mouvements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $mouvements = [];
}

/* Helpers historique */
function label_lieu(?string $type, ?int $id, ?string $chantierNom): string
{
    if ($type === 'depot') return 'Dépôt';
    if ($type === 'chantier') return $chantierNom ? ('Chantier : ' . $chantierNom) : 'Chantier';
    return '-';
}
function badge_statut(string $statut): string
{
    $map = ['valide' => 'success', 'refuse' => 'danger', 'annule' => 'danger'];
    $cls = $map[$statut] ?? 'secondary';
    return "<span class=\"badge bg-$cls text-uppercase\">$statut</span>";
}

/* Libellés contexte (badges en-tête) */
$chantierNomCtx = null;
$depotNomCtx = null;
if ($chantierId > 0) {
    $stmt = $pdo->prepare("SELECT nom FROM chantiers WHERE id = ? LIMIT 1");
    $stmt->execute([$chantierId]);
    $chantierNomCtx = $stmt->fetchColumn();
}
if ($depotId > 0) {
    $stmt = $pdo->prepare("SELECT nom FROM depots WHERE id = ? LIMIT 1");
    $stmt->execute([$depotId]);
    $depotNomCtx = $stmt->fetchColumn();
}

/* Historique : toujours affiché (comme admin/chef) */
$showHistorique = true;
?>
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
                <span class="badge bg-secondary">Chantier : <?= htmlspecialchars($chantierNomCtx ?: "#$chantierId") ?></span>
            <?php endif; ?>
            <?php if ($depotId > 0): ?>
                <span class="badge bg-info">Dépôt : <?= htmlspecialchars($depotNomCtx ?: "#$depotId") ?></span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Cartes quantités + photo -->
    <div class="row g-4 mb-4">
        <!-- Image -->
        <div class="col-md-4">
            <div class="card shadow-sm">
                <?php if (!empty($article['photo'])): ?>
                    <?php $photoUrl = '/' . ltrim($article['photo'], '/'); ?>
                    <img src="<?= htmlspecialchars($photoUrl) ?>"
                        class="card-img-top img-fluid"
                        alt="Photo de l'article"
                        style="max-height: 320px; object-fit: contain;">
                <?php else: ?>
                    <div class="text-muted text-center p-4">Aucune photo disponible</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quantités -->
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
                    <?php
                    $alertClass = $currentQtyContext > 0 ? 'alert-success' : 'alert-danger';
                    ?>
                    <div class="alert <?= $alertClass ?> mt-3 mb-0 py-2">
                        <?= htmlspecialchars($currentLabelContext) ?> : <strong><?= (int)$currentQtyContext ?></strong>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Onglets (sans "Actions") -->
    <ul class="nav nav-tabs mb-3" id="articleTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="details-tab" data-bs-toggle="tab" data-bs-target="#details" type="button" role="tab">Détails</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="files-tab" data-bs-toggle="tab" data-bs-target="#files" type="button" role="tab">Documents</button>
        </li>
        <?php if ($showHistorique): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab">Historique</button>
            </li>
        <?php endif; ?>
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
        <?php if ($showHistorique): ?>
            <div class="tab-pane fade" id="history" role="tabpanel">
                <div class="card shadow-sm p-3">
                    <h5 class="fw-bold mb-3">Historique des transferts</h5>

                    <?php if (empty($mouvements)): ?>
                        <div class="alert alert-light border d-flex align-items-center mb-0">
                            <span class="me-2">🕘</span> Aucun mouvement enregistré pour cet article.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>De</th>
                                        <th>Vers</th>
                                        <th class="text-end">Qté</th>
                                        <th>Statut</th>
                                        <th>Par</th>
                                        <th>Note</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($mouvements as $mv):
                                        $date = date('d/m/Y H:i', strtotime($mv['created_at']));
                                        $from = label_lieu($mv['source_type'], $mv['source_id'], $mv['source_chantier_nom'] ?? null);
                                        $to   = label_lieu($mv['dest_type'],   $mv['dest_id'],   $mv['dest_chantier_nom']   ?? null);
                                        $qte  = (int)$mv['quantite'];
                                        $by   = trim(($mv['user_prenom'] ?? '') . ' ' . ($mv['user_nom'] ?? ''));
                                        $by   = $by !== '' ? htmlspecialchars($by) : '-';
                                    ?>
                                        <tr>
                                            <td><?= $date ?></td>
                                            <td><?= htmlspecialchars($from) ?></td>
                                            <td><?= htmlspecialchars($to) ?></td>
                                            <td class="text-end fw-bold"><?= $qte ?></td>
                                            <td><?= badge_statut($mv['statut']) ?></td>
                                            <td><?= $by ?></td>
                                            <td class="text-muted"><?= htmlspecialchars($mv['commentaire'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>