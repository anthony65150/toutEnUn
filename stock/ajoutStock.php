<?php
declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/QrHelper.php';

/* ================================
   MULTI-ENTREPRISE (helpers)
================================ */
$ENT_ID = $_SESSION['utilisateurs']['entreprise_id'] ?? null;
$ENT_ID = is_numeric($ENT_ID) ? (int)$ENT_ID : null;

function me_where_first(?int $ENT_ID, string $alias = ''): array{
    if ($ENT_ID === null) return ['', []];
    $pfx = $alias ? $alias . '.' : '';
    return [" WHERE {$pfx}entreprise_id = :eid ", [':eid' => $ENT_ID]];
}
function me_where(?int $ENT_ID, string $alias = ''): array{
    if ($ENT_ID === null) return ['', []];
    $pfx = $alias ? $alias . '.' : '';
    return [" AND {$pfx}entreprise_id = :eid ", [':eid' => $ENT_ID]];
}

/* =========================================================
   AJAX: sous-catégories par catégorie
========================================================= */
if (isset($_GET['action']) && $_GET['action'] === 'getSousCategories' && !empty($_GET['categorie'])) {
    header('Content-Type: application/json');
    $categorie = trim((string)$_GET['categorie']);

    [ $frag, $p ] = me_where($ENT_ID, 's');
    $sql = "
        SELECT DISTINCT s.sous_categorie
        FROM stock s
        WHERE s.categorie = :c AND s.sous_categorie IS NOT NULL AND s.sous_categorie <> ''
        {$frag}
        ORDER BY s.sous_categorie
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([':c' => $categorie], $p));
    echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
    exit;
}

/* =========================================================
   Accès admin
========================================================= */
if (!isset($_SESSION['utilisateurs']) || ($_SESSION['utilisateurs']['fonction'] ?? null) !== 'administrateur') {
    header("Location: /connexion.php");
    exit;
}

/* =========================================================
   Helpers
========================================================= */
function web_to_abs(string $webPath): string{
    $rel = ltrim($webPath, '/');
    return rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . $rel;
}

$errors  = [];
$success = false;

/**
 * Génère le QR de la ligne stock et enregistre le token + le chemin image.
 * Utilise generateStockQr() de lib/QrHelper.php
 * ⚠️ Dans QrHelper.php, fais bien pointer l'URL vers
 *    /stock/article.php?t={TOKEN}&tab=etat
 */
function generate_qr_for_article(int $stockId, string $label = ''): void{
    global $pdo, $ENT_ID;

    // token unique (uuid v4 fourni par ton projet)
    $qrToken = uuidv4();

    // génère l'image (ABS) et récupère le chemin web
    $baseUrl   = app_base_url();
    $entId     = (int)($ENT_ID ?? 0);
    $absPath   = generateStockQr($baseUrl, $entId, $stockId, $qrToken); // génère le PNG
   $qrPathWeb = '/stock/qrcodes/' . $entId . "/stock_{$stockId}.png";

    // enregistre sur la ligne stock
    $pdo->prepare("UPDATE stock SET qr_token = ?, qr_image_path = ? WHERE id = ?")
        ->execute([$qrToken, $qrPathWeb, $stockId]);
}

/* =========================================================
   POST
========================================================= */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {

    // Normalisation propre (trim + espaces multiples -> 1 + casse)
    $normalize = function (?string $s): string {
        $s = trim((string)$s);
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = mb_strtolower($s, 'UTF-8');
        if ($s === '') return '';
        return mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($s, 1, null, 'UTF-8');
    };

    $nom       = ucfirst(mb_strtolower(trim($_POST['nom'] ?? ''), 'UTF-8'));
    $quantite  = (int)($_POST['quantite'] ?? 0);

    // Catégorie
    $categorie = '';
    if (!empty($_POST['nouvelleCategorie'])) {
        $categorie = ucfirst(mb_strtolower(trim($_POST['nouvelleCategorie']), 'UTF-8'));
    } elseif (!empty($_POST['categorieSelect'])) {
        $categorie = trim((string)$_POST['categorieSelect']);
    }

    // Sous-catégorie
    $sous_categorie = '';
    if (!empty($_POST['nouvelleSousCategorie'])) {
        $sous_categorie = ucfirst(mb_strtolower(trim($_POST['nouvelleSousCategorie']), 'UTF-8'));
    } elseif (!empty($_POST['sous_categorieSelect'])) {
        $sous_categorie = trim((string)$_POST['sous_categorieSelect']);
    }

    // Fichiers (photo + document)
    $photo    = $_FILES['photo']    ?? null;
    $document = $_FILES['document'] ?? null;

    // Validations de base
    if ($nom === '')         $errors['nom'] = "Le nom est obligatoire.";
    if ($quantite < 0)       $errors['quantite'] = "La quantité doit être positive.";
    if ($categorie === '')   $errors['categorie'] = "La catégorie est obligatoire.";

    // Pré-validation doc (optionnelle)
    $document_path_web = null;
    if ($document && $document['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($document['error'] !== UPLOAD_ERR_OK) {
            $errors['document'] = "Erreur lors de l’upload du document.";
        } else {
            $ext = strtolower(pathinfo($document['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'webp'];
            if (!in_array($ext, $allowed, true)) {
                $errors['document'] = "Type de fichier non autorisé.";
            }
        }
    }

    // Pré-validation photo (optionnelle)
    $photo_path_web = null;
    if ($photo && $photo['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($photo['error'] !== UPLOAD_ERR_OK) {
            $errors['photo'] = "Erreur lors de l’upload de la photo.";
        } else {
            $extp = strtolower(pathinfo($photo['name'], PATHINFO_EXTENSION));
            $allowedImg = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array($extp, $allowedImg, true)) {
                $errors['photo'] = "Format d’image non autorisé.";
            }
        }
    }

    if (empty($errors)) {
        try {
            // =========
            // Prépare: inputs + uploads temporaires
            // =========
            $gestionMode = in_array(($_POST['gestion_mode'] ?? ''), ['anonyme','nominatif'], true)
                ? $_POST['gestion_mode'] : 'anonyme';

            // Nouveau: lecture du profil QR + mapping vers maintenance_mode
            $profilQr = in_array(($_POST['profil_qr'] ?? ''), ['aucun','compteur_heures','autre'], true)
                ? $_POST['profil_qr'] : 'aucun';

            $maintenanceMode = match ($profilQr) {
                'compteur_heures' => 'hour_meter',
                'autre'           => 'electrical',
                default           => 'none',
            };

            $tmp_doc_abs = $tmp_doc_ext = null;
            if ($document && $document['error'] === UPLOAD_ERR_OK) {
                $tmp_doc_ext = strtolower(pathinfo($document['name'], PATHINFO_EXTENSION));
                $tmp_doc_abs = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/uploads/tmp/' . uniqid('doc_', true) . '.' . $tmp_doc_ext;
                @mkdir(dirname($tmp_doc_abs), 0775, true);
                if (!move_uploaded_file($document['tmp_name'], $tmp_doc_abs)) {
                    throw new RuntimeException("Échec de l’enregistrement du document.");
                }
            }

            $tmp_img_abs = $tmp_img_ext = null;
            if ($photo && $photo['error'] === UPLOAD_ERR_OK) {
                $tmp_img_ext = strtolower(pathinfo($photo['name'], PATHINFO_EXTENSION));
                $tmp_img_abs = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/uploads/tmp/' . uniqid('img_', true) . '.' . $tmp_img_ext;
                @mkdir(dirname($tmp_img_abs), 0775, true);
                if (!move_uploaded_file($photo['tmp_name'], $tmp_img_abs)) {
                    throw new RuntimeException("Échec de l’enregistrement de la photo.");
                }
            }

            // =========
            // Trouver le dépôt par défaut de l'entreprise
            // =========
            $sqlDepot = "SELECT d.id FROM depots d";
            [ $fragD, $pD ] = me_where_first($ENT_ID, 'd');
            $sqlDepot .= $fragD . " ORDER BY d.id ASC LIMIT 1";
            $stDepot = $pdo->prepare($sqlDepot);
            $stDepot->execute($pD);
            $depotId = (int)($stDepot->fetchColumn() ?: 0);
            if ($depotId <= 0) {
                throw new RuntimeException("Aucun dépôt n'est configuré pour cette entreprise.");
            }

            // =========
            // Branches: ANONYME = 1 ligne; NOMINATIF = N lignes (quantite=1)
            // =========
            if ($gestionMode === 'anonyme') {
                $pdo->beginTransaction();

                // 1) Crée l'article (une ligne)
                $stmt = $pdo->prepare("
                    INSERT INTO stock
                      (nom, quantite_totale, quantite_disponible, categorie, sous_categorie, photo, document, entreprise_id, gestion_mode, maintenance_mode, profil_qr)
                    VALUES
                      (?, ?, 0, ?, ?, NULL, NULL, ?, 'anonyme', ?, ?)
                ");
                $stmt->execute([
                    $nom,
                    $quantite,
                    $categorie,
                    $sous_categorie !== '' ? $sous_categorie : null,
                    $ENT_ID,
                    $maintenanceMode,
                    $profilQr,
                ]);
                $stockId = (int)$pdo->lastInsertId();

                // QR (token + image + champs)
                $qrToken   = uuidv4();
                $qrPathAbs = generateStockQr(app_base_url(), (int)$ENT_ID, $stockId, $qrToken); // ⚠️ ajouter &tab=etat dans le helper
               $qrPathWeb = '/stock/qrcodes/' . ((int)$ENT_ID) . "/stock_{$stockId}.png";
                $pdo->prepare("UPDATE stock SET qr_token = ?, qr_image_path = ? WHERE id = ?")
                    ->execute([$qrToken, $qrPathWeb, $stockId]);

                // Fichiers (copie depuis /uploads/tmp/)
                if ($tmp_doc_abs) {
                    $dir_web = "uploads/documents/articles/{$stockId}/";
                    $dir_abs = web_to_abs($dir_web);
                    @mkdir($dir_abs, 0775, true);
                    $doc_name = uniqid('', true) . '.' . $tmp_doc_ext;
                    copy($tmp_doc_abs, $dir_abs . $doc_name);
                    $pdo->prepare("UPDATE stock SET document=? WHERE id=?")->execute([$dir_web . $doc_name, $stockId]);
                }
                if ($tmp_img_abs) {
                    $dir_web = "uploads/photos/articles/{$stockId}/";
                    $dir_abs = web_to_abs($dir_web);
                    @mkdir($dir_abs, 0775, true);
                    $img_name = uniqid('', true) . '.' . $tmp_img_ext;
                    copy($tmp_img_abs, $dir_abs . $img_name);
                    $pdo->prepare("UPDATE stock SET photo=? WHERE id=?")->execute([$dir_web . $img_name, $stockId]);
                }

                // Dépôt + dispo
                $ins = $pdo->prepare("INSERT INTO stock_depots (stock_id, depot_id, quantite, entreprise_id) VALUES (?, ?, ?, ?)");
                $ins->execute([$stockId, $depotId, $quantite, $ENT_ID]);

                $stSum = $pdo->prepare("
                    UPDATE stock s
                       SET quantite_disponible = (
                         SELECT COALESCE(SUM(sd.quantite),0)
                         FROM stock_depots sd JOIN depots d ON d.id=sd.depot_id
                         WHERE sd.stock_id = s.id AND d.entreprise_id = :eid
                       )
                     WHERE s.id = :sid
                ");
                $stSum->execute([':eid' => $ENT_ID, ':sid' => $stockId]);

                // QR générique (même token mais via helper “joli” si besoin)
                generate_qr_for_article($stockId, $nom);

                $pdo->commit();
                $success = true;

            } else {
                // ===== NOMINATIF =====
                $N = max(1, (int)$quantite);
                if ($N > 500) {
                    throw new RuntimeException("Nombre d’unités trop élevé.");
                }

                // Trouver le prochain suffixe (évite les doublons)
                $q = $pdo->prepare("
                    SELECT nom FROM stock
                     WHERE entreprise_id = :eid
                       AND gestion_mode = 'nominatif'
                       AND (nom = :base OR nom LIKE :like)
                ");
                $q->execute([':eid' => $ENT_ID, ':base' => $nom, ':like' => $nom . ' %']);
                $max = 0;
                while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
                    if (preg_match('/\s(\d+)$/', $r['nom'], $m)) {
                        $max = max($max, (int)$m[1]);
                    }
                }
                $start = $max + 1;

                $pdo->beginTransaction();

                $ins = $pdo->prepare("
                    INSERT INTO stock
                      (nom, quantite_totale, quantite_disponible, categorie, sous_categorie, photo, document, entreprise_id, gestion_mode, maintenance_mode, profil_qr)
                    VALUES
                      (?, 1, 0, ?, ?, NULL, NULL, ?, 'nominatif', ?, ?)
                ");
                $insDepot = $pdo->prepare("
                    INSERT INTO stock_depots (stock_id, depot_id, quantite, entreprise_id) VALUES (?, ?, 1, ?)
                ");

                for ($i = 0; $i < $N; $i++) {
                    $nomUnit = $nom . ' ' . ($start + $i);

                    // crée la ligne
                    $ins->execute([
                        $nomUnit,
                        $categorie,
                        $sous_categorie !== '' ? $sous_categorie : null,
                        $ENT_ID,
                        $maintenanceMode,
                        $profilQr,
                    ]);
                    $sid = (int)$pdo->lastInsertId();

                    // QR (token + image + champs)
                    $qrToken   = uuidv4();
                    $qrPathAbs = generateStockQr(app_base_url(), (int)$ENT_ID, $sid, $qrToken); // ⚠️ ajouter &tab=etat dans le helper
                    $qrPathWeb = '/stock/qrcodes/' . ((int)$ENT_ID) . "/stock_{$sid}.png";
                    $pdo->prepare("UPDATE stock SET qr_token = ?, qr_image_path = ? WHERE id = ?")
                        ->execute([$qrToken, $qrPathWeb, $sid]);

                    // fichiers (copie du tmp vers dossier de cette ligne)
                    if ($tmp_doc_abs) {
                        $dir_web = "uploads/documents/articles/{$sid}/";
                        $dir_abs = web_to_abs($dir_web);
                        @mkdir($dir_abs, 0775, true);
                        $doc_name = uniqid('', true) . '.' . $tmp_doc_ext;
                        copy($tmp_doc_abs, $dir_abs . $doc_name);
                        $pdo->prepare("UPDATE stock SET document=? WHERE id=?")->execute([$dir_web . $doc_name, $sid]);
                    }
                    if ($tmp_img_abs) {
                        $dir_web = "uploads/photos/articles/{$sid}/";
                        $dir_abs = web_to_abs($dir_web);
                        @mkdir($dir_abs, 0775, true);
                        $img_name = uniqid('', true) . '.' . $tmp_img_ext;
                        copy($tmp_img_abs, $dir_abs . $img_name);
                        $pdo->prepare("UPDATE stock SET photo=? WHERE id=?")->execute([$dir_web . $img_name, $sid]);
                    }

                    // met 1 au dépôt + dispo (=1)
                    $insDepot->execute([$sid, $depotId, $ENT_ID]);
                    $pdo->prepare("UPDATE stock SET quantite_disponible = 1 WHERE id = ?")->execute([$sid]);

                    // QR individuel (helper)
                    generate_qr_for_article($sid, $nomUnit);
                }

                $pdo->commit();
                $success = true;
            }

            // Nettoyage des fichiers temporaires
            if ($tmp_doc_abs && file_exists($tmp_doc_abs)) @unlink($tmp_doc_abs);
            if ($tmp_img_abs && file_exists($tmp_img_abs)) @unlink($tmp_img_abs);
        }
        catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors['general'] = "Erreur lors de l'ajout : " . $e->getMessage();
        }
    }
}

/* =========================================================
   Données pour le formulaire
========================================================= */
[ $fragCat, $pCat ] = me_where_first($ENT_ID, 's');
$sql = "
    SELECT DISTINCT s.categorie
    FROM stock s
    {$fragCat}
    AND s.categorie IS NOT NULL AND s.categorie <> ''
    ORDER BY s.categorie
";
$stmt = $pdo->prepare($sql);
$stmt->execute($pCat);
$categoriesExistantes = $stmt->fetchAll(PDO::FETCH_COLUMN);

$sousCategoriesExistantes = [];
$categorieSelectionnee = $_POST['nouvelleCategorie'] ?? ($_POST['categorieSelect'] ?? '');
if ($categorieSelectionnee !== '') {
    [ $fragSub, $pSub ] = me_where($ENT_ID, 's');
    $stmtSC = $pdo->prepare("
        SELECT DISTINCT s.sous_categorie
        FROM stock s
        WHERE s.categorie = :c AND s.sous_categorie IS NOT NULL AND s.sous_categorie <> ''
        {$fragSub}
        ORDER BY s.sous_categorie
    ");
    $stmtSC->execute(array_merge([':c' => $categorieSelectionnee], $pSub));
    $sousCategoriesExistantes = $stmtSC->fetchAll(PDO::FETCH_COLUMN);
}

/* =========================================================
   Header + Nav
========================================================= */
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../templates/navigation/navigation.php';
?>

<body
    <?= !empty($_POST['nouvelleCategorie']) ? 'data-nouvelle-categorie="1"' : '' ?>
    <?= !empty($_POST['nouvelleSousCategorie']) ? ' data-nouvelle-sous-categorie="1"' : '' ?>>
    <div class="fond-gris">
        <div class="p-5">
            <div class="container mt-1">
                <h1 class="mb-3 text-center">Ajouter un élément au dépôt</h1>

                <?php if ($success): ?>
                    <div class="alert alert-success text-center">✅ Élément ajouté avec succès au dépôt.</div>
                <?php elseif (!empty($errors['general'])): ?>
                    <div class="alert alert-danger text-center"><?= htmlspecialchars($errors['general']) ?></div>
                <?php endif; ?>

                <form action="" method="POST" enctype="multipart/form-data" class="mx-auto" style="max-width: 1000px;">
                    <div class="mb-3">
                        <label for="nom" class="form-label">Nom de l'élément</label>
                        <input type="text" name="nom" id="nom" class="form-control"
                            value="<?= htmlspecialchars($_POST['nom'] ?? '') ?>">
                        <?php if (!empty($errors['nom'])): ?>
                            <div class="alert alert-danger mt-1"><?= $errors['nom'] ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="quantite" class="form-label">Quantité totale</label>
                        <input type="number" name="quantite" id="quantite" min="0" class="form-control"
                            value="<?= htmlspecialchars($_POST['quantite'] ?? '') ?>">
                        <?php if (!empty($errors['quantite'])): ?>
                            <div class="alert alert-danger mt-1"><?= $errors['quantite'] ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Mode de gestion -->
                    <div class="mb-3">
                        <label class="form-label">Mode de gestion</label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="gestion_mode" id="mode_anonyme" value="anonyme"
                                   <?= (($_POST['gestion_mode'] ?? 'anonyme') === 'anonyme') ? 'checked' : '' ?>>
                            <label class="form-check-label" for="mode_anonyme">Anonyme (une ligne avec quantité)</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="gestion_mode" id="mode_nominatif" value="nominatif"
                                   <?= (($_POST['gestion_mode'] ?? '') === 'nominatif') ? 'checked' : '' ?>>
                            <label class="form-check-label" for="mode_nominatif">Nominatif (une ligne/QR par unité)</label>
                        </div>
                    </div>

                    <!-- Profil QR / entretien -->
                    <div class="mb-3">
                        <label class="form-label">Profil QR / entretien</label>
                        <select name="profil_qr" id="profil_qr" class="form-control">
                            <?php $sel = fn($v)=> (($_POST['profil_qr'] ?? 'aucun')===$v)?'selected':''; ?>
                            <option value="aucun" <?= $sel('aucun') ?>>Aucun</option>
                            <option value="compteur_heures" <?= $sel('compteur_heures') ?>>Machine avec compteur d'heures</option>
                            <option value="autre" <?= $sel('autre') ?>>Autres (déclarer problème/OK)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="categorieSelect" class="form-label">Catégorie</label>
                        <select name="categorieSelect" id="categorieSelect" class="form-control form-select" onchange="toggleNewCategoryInput()">
                            <option value="" disabled <?= empty($_POST['categorieSelect']) && empty($_POST['nouvelleCategorie']) ? 'selected' : '' ?>>-- Sélectionner une catégorie --</option>
                            <?php foreach ($categoriesExistantes as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>" <?= (($_POST['categorieSelect'] ?? '') === $cat) ? 'selected' : '' ?>>
                                    <?= ucfirst(htmlspecialchars($cat)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="btnAddCategory" class="btn btn-link p-0 mt-1" onclick="showNewCategoryInput()">+ Ajouter une catégorie</button>
                        <?php if (!empty($errors['categorie'])): ?>
                            <div class="alert alert-danger mt-1"><?= $errors['categorie'] ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3" id="newCategorieDiv" style="display: none;">
                        <label for="nouvelleCategorie" class="form-label">Nouvelle catégorie</label>
                        <input type="text" name="nouvelleCategorie" id="nouvelleCategorie" class="form-control"
                               value="<?= htmlspecialchars($_POST['nouvelleCategorie'] ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label for="sous_categorieSelect" class="form-label">Sous-catégorie (optionnel)</label>
                        <select name="sous_categorieSelect" id="sous_categorieSelect" class="form-control form-select" onchange="toggleNewSubCategoryInput()">
                            <option value="" disabled <?= empty($_POST['sous_categorieSelect']) && empty($_POST['nouvelleSousCategorie']) ? 'selected' : '' ?>>-- Sélectionner une sous-catégorie --</option>
                            <?php foreach ($sousCategoriesExistantes as $sc): ?>
                                <option value="<?= htmlspecialchars($sc) ?>" <?= (($_POST['sous_categorieSelect'] ?? '') === $sc) ? 'selected' : '' ?>>
                                    <?= ucfirst(htmlspecialchars($sc)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="btnAddSubCategory" class="btn btn-link p-0 mt-1" onclick="showNewSubCategoryInput()">+ Ajouter une sous-catégorie</button>
                    </div>

                    <div class="mb-3" id="newSousCategorieDiv" style="display: none;">
                        <label for="nouvelleSousCategorie" class="form-label">Nouvelle sous-catégorie</label>
                        <input type="text" name="nouvelleSousCategorie" id="nouvelleSousCategorie" class="form-control"
                               value="<?= htmlspecialchars($_POST['nouvelleSousCategorie'] ?? '') ?>">
                    </div>

                    <div class="mb-4">
                        <label for="photo" class="form-label">Photo (optionnel)</label>
                        <input type="file" name="photo" id="photo" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp">
                    </div>

                    <div class="mb-3">
                        <label for="modifierDocument" class="form-label">Document technique (PDF, notice, etc.)</label>
                        <input type="file" name="document" id="modifierDocument" class="form-control"
                               accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.webp">
                    </div>

                    <div class="text-center mt-5">
                        <button type="submit" class="btn btn-primary w-50">Ajouter au dépôt</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="/stock/js/ajoutStock.js"></script>

<?php
require_once __DIR__ . '/../templates/footer.php';
ob_end_flush();
