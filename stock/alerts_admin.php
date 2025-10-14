<?php
// Fichier: /stock/alerts_admin.php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';

// ==== Sécurité : admin requis ====
$role = (string)($_SESSION['utilisateurs']['fonction'] ?? '');
if (!isset($_SESSION['utilisateurs']) || !in_array($role, ['administrateur','admin'], true)) {
    header("Location: ../connexion.php");
    exit;
}

require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/navigation/navigation.php';

// ===== Multi-entreprise =====
$ENT_ID = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);

// ===== Recherche (facultatif) =====
$search   = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$typeFilt = isset($_GET['type']) ? trim((string)$_GET['type']) : ''; // 'problem' | 'maintenance_due' | ''

// ===== Récup des alertes NON archivées : Pannes + Entretiens =====
$params = [':eid' => $ENT_ID];

$sql = "
    SELECT 
        sa.id              AS alert_id,
        sa.message,
        sa.created_at,
        sa.is_read,
        sa.url             AS alert_url,
        s.id               AS stock_id,
        s.nom              AS article_nom
    FROM stock_alerts sa
    JOIN stock s ON s.id = sa.stock_id
    WHERE s.entreprise_id = :eid
      AND sa.archived_at IS NULL
      AND sa.type = 'incident'
      AND sa.url IN ('maintenance_due','problem')
";

if ($typeFilt === 'problem' || $typeFilt === 'maintenance_due') {
    $sql .= " AND sa.url = :type ";
    $params[':type'] = $typeFilt;
}

if ($search !== '') {
    $sql .= " AND (s.nom LIKE :q OR sa.message LIKE :q) ";
    $params[':q'] = '%' . $search . '%';
}

$sql .= " ORDER BY sa.created_at DESC, sa.id DESC";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$count = count($rows);

// CSRF simple pour les POST (archive)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Helpers affichage
function badgeType(string $url): string {
    if ($url === 'problem')          return '<span class="badge bg-danger">PANNE</span>';
    if ($url === 'maintenance_due')  return '<span class="badge bg-warning text-dark">ENTRETIEN</span>';
    return '<span class="badge bg-secondary">ALERTE</span>';
}
?>
<div class="container my-4">
    <h2 class="mb-3">
        Alertes (pannes + entretiens)
        <span class="badge bg-secondary align-middle"><?= (int)$count ?></span>
    </h2>

    <form class="mb-3" method="get" action="">
        <div class="row g-2 align-items-end">
            <div class="col-auto">
                <label for="type" class="form-label mb-0 small text-muted">Type</label>
                <select name="type" id="type" class="form-select">
                    <option value="">Tous</option>
                    <option value="problem" <?= $typeFilt==='problem' ? 'selected' : '' ?>>Pannes</option>
                    <option value="maintenance_due" <?= $typeFilt==='maintenance_due' ? 'selected' : '' ?>>Entretiens</option>
                </select>
            </div>
            <div class="col-auto">
                <label for="q" class="form-label mb-0 small text-muted">Recherche</label>
                <input type="text" name="q" id="q" class="form-control" placeholder="Article, message…"
                       value="<?= htmlspecialchars($search, ENT_QUOTES) ?>">
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-secondary">Rechercher</button>
            </div>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-striped table-bordered table-hover text-center align-middle">
            <thead class="table-dark">
                <tr>
                    <th style="width:80px">#</th>
                    <th style="width:140px">Type</th>
                    <th>Article</th>
                    <th>Message</th>
                    <th style="width:180px">Créée le</th>
                    <th style="width:80px">Lu ?</th>
                    <th class="text-end" style="width:240px">Actions</th>
                </tr>
            </thead>
            <tbody id="alertsTableBody">
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">Aucune alerte.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): 
                        $aid  = (int)$r['alert_id'];
                        $sid  = (int)$r['stock_id'];
                        $msg  = (string)$r['message'];
                        $date = $r['created_at'] ? date('d/m/Y H:i', strtotime((string)$r['created_at'])) : '';
                        $read = ((int)$r['is_read'] === 1);
                        $url  = (string)$r['alert_url'];
                    ?>
                        <tr data-row-id="<?= $aid ?>" data-stock-id="<?= $sid ?>">
                            <td>#<?= $aid ?></td>
                            <td><?= badgeType($url) ?></td>
                            <td><?= htmlspecialchars((string)$r['article_nom'], ENT_QUOTES) ?></td>
                            <td class="text-start"><?= htmlspecialchars($msg, ENT_QUOTES) ?></td>
                            <td><?= $date ?></td>
                            <td class="col-read"><?= $read ? 'Oui' : 'Non' ?></td>
                            <td class="text-end">
                                <button type="button"
                                    class="btn btn-sm btn-outline-primary open-btn"
                                    data-id="<?= $aid ?>"
                                    data-stock-id="<?= $sid ?>">
                                    Ouvrir
                                </button>

                                <button type="button"
                                    class="btn btn-sm btn-outline-danger ms-1 archive-btn"
                                    data-id="<?= $aid ?>"
                                    data-csrf="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                                    Supprimer
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Archiver (soft-delete) via AJAX avec CSRF
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.archive-btn');
    if (!btn) return;

    const id   = parseInt(btn.dataset.id, 10);
    const csrf = btn.dataset.csrf || '';
    if (!id)  { alert('ID manquant'); return; }

    if (!confirm("Retirer cette alerte de la liste ?\n(L'historique restera dans la fiche article)")) return;

    btn.disabled = true;

    try {
        const res  = await fetch('archive_alert.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ id, csrf })
        });
        const json = await res.json();

        if (!json.ok) throw new Error(json.msg || 'Erreur');

        const tr = btn.closest('tr');
        tr.style.transition = 'background-color .4s, opacity .4s';
        tr.style.backgroundColor = '#f8d7da';
        tr.style.opacity = '0';
        setTimeout(() => tr.remove(), 320);
    } catch (err) {
        alert(err.message || 'Erreur réseau');
        btn.disabled = false;
    }
});

// Ouvrir : marque l’alerte LU puis redirige vers la fiche article
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.open-btn');
    if (!btn) return;

    const id      = parseInt(btn.dataset.id, 10);
    const stockId = parseInt(btn.dataset.stockId, 10);
    if (!id || !stockId) return;

    btn.disabled = true;

    try {
        await fetch('api/alert_mark_read.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({ id })
        });

        // maj UI immédiate
        const row = btn.closest('tr');
        const readCell = row?.querySelector('.col-read');
        if (readCell) readCell.textContent = 'Oui';

        // redirection vers la fiche article
        window.location.href = 'article.php?id=' + stockId;

    } catch (err) {
        alert('Impossible de marquer comme lu.');
        btn.disabled = false;
    }
});
</script>

<?php
require_once __DIR__ . '/../templates/footer.php';
