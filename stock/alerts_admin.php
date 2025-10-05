<?php
// Fichier: /stock/alerts_admin.php
declare(strict_types=1);

require_once __DIR__ . '/../config/init.php';

// ==== Sécurité : admin requis ====
if (!isset($_SESSION['utilisateurs']) || (($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur')) {
    header("Location: ../connexion.php");
    exit;
}

require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/navigation/navigation.php';

// ===== Multi-entreprise =====
$ENT_ID = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);

// ===== Recherche (facultatif) =====
$search = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

// ===== Récup des alertes NON archivées =====
// Table: stock_alerts (id, entreprise_id, stock_id, type, message, url, created_at, is_read, archived_at, archived_by)
$params = [':eid' => $ENT_ID];
$sql = "
    SELECT 
        sa.id              AS alert_id,
        sa.message,
        sa.created_at,
        sa.is_read,
        s.id               AS stock_id,
        s.nom              AS article_nom
    FROM stock_alerts sa
    JOIN stock s ON s.id = sa.stock_id
    WHERE s.entreprise_id = :eid
      AND sa.archived_at IS NULL        -- << ne montre pas les archivées
";

if ($search !== '') {
    $sql .= " AND (s.nom LIKE :q OR sa.message LIKE :q) ";
    $params[':q'] = '%'.$search.'%';
}

$sql .= " ORDER BY sa.id DESC";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$count = count($rows);

// CSRF si besoin
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
?>
<div class="container my-4">
    <h2 class="mb-3">
        Alertes incidents 
        <span class="badge bg-secondary align-middle"><?= (int)$count ?></span>
    </h2>


    <div class="table-responsive">
        <table class="table table-striped table-bordered table-hover text-center align-middle">
            <thead class="table-dark">
                <tr>
                    <th style="width:80px">#</th>
                    <th>Article</th>
                    <th>Message</th>
                    <th style="width:180px">Créée le</th>
                    <th style="width:80px">Lu ?</th>
                    <th class="text-end" style="width:220px">Actions</th>
                </tr>
            </thead>
            <tbody id="alertsTableBody">
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">Aucune alerte.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr data-row-id="<?= (int)$r['alert_id'] ?>">
                            <td>#<?= (int)$r['alert_id'] ?></td>
                            <td><?= htmlspecialchars((string)$r['article_nom']) ?></td>
                            <td><?= htmlspecialchars((string)$r['message']) ?></td>
                            <td><?= $r['created_at'] ? date('d/m/Y H:i', strtotime((string)$r['created_at'])) : '' ?></td>
                            <td><?= ((int)$r['is_read'] === 1) ? 'Oui' : 'Non' ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary"
                                   href="article.php?id=<?= (int)$r['stock_id'] ?>">
                                    Ouvrir
                                </a>
                                <button type="button"
                                        class="btn btn-sm btn-outline-danger ms-1 archive-btn"
                                        data-id="<?= (int)$r['alert_id'] ?>"
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
// Archiver (soft-delete) via AJAX : retire de la liste, reste visible dans la fiche article.
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.archive-btn');
    if (!btn) return;

    const id = parseInt(btn.dataset.id, 10);
    if (!id) { alert('ID manquant'); return; }

    if (!confirm("Retirer cette alerte de la liste ?\n(L'historique restera dans la fiche article)")) return;

    btn.disabled = true;

    try {
        const res = await fetch('archive_alert.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                id: id
                // , csrf: btn.dataset.csrf   // décommente si tu valides aussi côté API
            })
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
</script>

<?php
require_once __DIR__ . '/../templates/footer.php';
