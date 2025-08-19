<?php
require_once "./config/init.php";
file_put_contents('debug_post.txt', var_export($_POST, true));

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
    exit;
}

// ---- Helpers ----
function resolveStoredPathToAbsolute(string $stored = null): ?string
{
    if (!$stored) return null;
    // Cas 1: chemin web sans slash initial "uploads/..." -> __DIR__."/uploads/..."
    if (strpos($stored, 'uploads/') === 0) {
        return __DIR__ . '/' . $stored;
    }
    // Cas 2: chemin web avec slash initial "/uploads/..." -> __DIR__."/uploads/..."
    if (strpos($stored, '/uploads/') === 0) {
        return __DIR__ . $stored;
    }
    // Cas legacy: la BDD stockait uniquement le nom de fichier -> dans "uploads/photos/"
    return __DIR__ . '/uploads/photos/' . $stored;
}
// Chemin absolu depuis un chemin web relatif (commençant en général par "uploads/")
function absFromRel(?string $rel): ?string
{
    if (!$rel) return null;
    return __DIR__ . '/' . ltrim($rel, '/');
}

$stockId  = isset($_POST['stockId']) ? (int)$_POST['stockId'] : null;
$nom      = trim($_POST['nom'] ?? '');
$quantite = isset($_POST['quantite']) ? (int)$_POST['quantite'] : null;

if (!$stockId || $nom === '' || $quantite === null || $quantite < 0) {
    echo json_encode(['success' => false, 'message' => 'Données invalides']);
    exit;
}

// =======================================================
// DOCUMENTS (NOUVEAU: multi via table stock_documents)
// =======================================================
$allowedDocExt = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'webp'];
$docsAdded   = [];
$docsDeleted = [];
$docsAll     = [];

$deleteDocIds = $_POST['deleteDocIds'] ?? '[]';
if (!is_array($deleteDocIds)) {
    $tmp = json_decode($deleteDocIds, true);
    $deleteDocIds = is_array($tmp) ? $tmp : [];
}
// Normaliser en int uniques
$deleteDocIds = array_values(array_unique(array_map('intval', $deleteDocIds)));

// Récup des nouveaux fichiers (input name="documents[]" multiple)
// + compat: si "document" (singulier) est encore envoyé, on l’ajoute aussi au lot.
$newDocsInput = $_FILES['documents'] ?? null;
$legacySingle = $_FILES['document']  ?? null;

$pendingInserts = []; // fichiers déplacés (ok) à insérer en BDD après

// Dossier cible par article
$docsDirRel = "uploads/documents/articles/" . $stockId . "/";
$docsDirAbs = __DIR__ . '/' . $docsDirRel;
if (!is_dir($docsDirAbs)) {
    @mkdir($docsDirAbs, 0775, true);
}

// 1) Préparer/mover les "documents[]" multiples
if ($newDocsInput && is_array($newDocsInput['name'])) {
    $count = count($newDocsInput['name']);
    for ($i = 0; $i < $count; $i++) {
        if ($newDocsInput['error'][$i] !== UPLOAD_ERR_OK) continue;
        $origName = $newDocsInput['name'][$i];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedDocExt)) continue;

        $uniq = uniqid('', true) . '.' . $ext;
        $destAbs = $docsDirAbs . $uniq;

        if (move_uploaded_file($newDocsInput['tmp_name'][$i], $destAbs)) {
            $relPath = $docsDirRel . $uniq;
            $pendingInserts[] = [
                'nom'   => $origName,
                'rel'   => $relPath,
                'mime'  => $newDocsInput['type'][$i] ?? null,
                'size'  => (int)($newDocsInput['size'][$i] ?? 0),
                'sha1'  => @sha1_file($destAbs) ?: null,
            ];
        }
    }
}

// 2) Compat: si "document" (singulier) est envoyé, on le traite comme un ajout multi
if ($legacySingle && $legacySingle['error'] === UPLOAD_ERR_OK) {
    $ext = strtolower(pathinfo($legacySingle['name'], PATHINFO_EXTENSION));
    if (in_array($ext, $allowedDocExt)) {
        $uniq = uniqid('', true) . '.' . $ext;
        $destAbs = $docsDirAbs . $uniq;
        if (move_uploaded_file($legacySingle['tmp_name'], $destAbs)) {
            $relPath = $docsDirRel . $uniq;
            $pendingInserts[] = [
                'nom'   => $legacySingle['name'],
                'rel'   => $relPath,
                'mime'  => $legacySingle['type'] ?? null,
                'size'  => (int)($legacySingle['size'] ?? 0),
                'sha1'  => @sha1_file($destAbs) ?: null,
            ];
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Type de fichier non autorisé (document).']);
        exit;
    }
}

// =======================================================
// PHOTO (inchangé, dossier par article)
// =======================================================
$photo       = $_FILES['photo'] ?? null;
$rawDeletePhoto = $_POST['deletePhoto'] ?? '0';
$deletePhoto = in_array($rawDeletePhoto, [1, '1', true, 'true', 'on', 'yes'], true);

$nom_photo_db = null; // chemin relatif web à stocker

try {
    // Récup ancienne photo
    $stmt = $pdo->prepare("SELECT photo FROM stock WHERE id = ?");
    $stmt->execute([$stockId]);
    $anciennePhotoStored = $stmt->fetchColumn();

    /**
     * Essaie de supprimer physiquement une photo à partir du chemin stocké en BDD.
     * Retourne true si un fichier a bien été supprimé.
     */
    function tryUnlinkStoredPhoto(?string $stored): bool
    {
        if (!$stored) return false;

        // Candidats de chemins absolus
        $candidates = [];

        // cas "uploads/photos/articles/42/xxx.jpg" (sans slash initial)
        if (strpos($stored, 'uploads/') === 0) {
            $candidates[] = __DIR__ . '/' . $stored;
        }

        // cas "/uploads/photos/articles/42/xxx.jpg" (avec slash initial)
        if (strpos($stored, '/uploads/') === 0) {
            $candidates[] = __DIR__ . $stored;                    // "/var/www/site" . "/uploads/..." => "/var/www/site/uploads/..."
            $candidates[] = __DIR__ . '/' . ltrim($stored, '/');  // fallback
        }

        // legacy: si la BDD contenait juste le nom de fichier
        if (strpos($stored, 'uploads/') !== 0 && strpos($stored, '/uploads/') !== 0) {
            $candidates[] = __DIR__ . '/uploads/photos/' . ltrim($stored, '/');
        }

        foreach ($candidates as $abs) {
            if (is_file($abs)) {
                @unlink($abs);
                return true;
            }
        }
        return false;
    }

    $photoDeletedPhysically = false;

    // Suppression explicite demandée ?
    if ($deletePhoto && $anciennePhotoStored) {
        $photoDeletedPhysically = tryUnlinkStoredPhoto($anciennePhotoStored);
        // 🔸 On ne "echo" rien ici : on mettra photo=NULL en BDD plus bas quoi qu'il arrive.
    }


    // Upload nouvelle photo
    if ($photo && $photo['error'] === UPLOAD_ERR_OK) {
        $extensionPhoto = strtolower(pathinfo($photo['name'], PATHINFO_EXTENSION));
        $extensionsAutorisees = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($extensionPhoto, $extensionsAutorisees)) {
            echo json_encode(['success' => false, 'message' => 'Format d’image non autorisé.']);
            exit;
        }

        $dirRel  = "uploads/photos/articles/" . $stockId . "/";
        $dirAbs  = __DIR__ . '/' . $dirRel;
        if (!is_dir($dirAbs)) {
            if (!@mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
                echo json_encode(['success' => false, 'message' => 'Impossible de créer le dossier de destination.']);
                exit;
            }
        }

        $nomFic  = uniqid('', true) . '.' . $extensionPhoto;
        $destAbs = $dirAbs . $nomFic;

        if (!move_uploaded_file($photo['tmp_name'], $destAbs)) {
            echo json_encode(['success' => false, 'message' => 'Échec de l’enregistrement de la photo.']);
            exit;
        }

        // Si on remplace et pas suppression déjà faite
        if ($anciennePhotoStored && !$deletePhoto) {
            $abs = resolveStoredPathToAbsolute($anciennePhotoStored);
            if ($abs && is_file($abs)) {
                @unlink($abs);
            }
        }

        $nom_photo_db = $dirRel . $nomFic;
    }

    // ===================================================
    // TRANSACTION (quantités + photo + docs DB)
    // ===================================================
    $pdo->beginTransaction();

    // Ancienne quantité (pour diff)
    $stmt = $pdo->prepare("SELECT quantite_totale FROM stock WHERE id = ?");
    $stmt->execute([$stockId]);
    $ancienneQuantite = (int)$stmt->fetchColumn();

    $diff = $quantite - $ancienneQuantite;

    // UPDATE stock
    $updateSql = "UPDATE stock SET nom = ?, quantite_totale = ?, quantite_disponible = quantite_disponible + ?";
    $params    = [$nom, $quantite, $diff];

    if ($nom_photo_db !== null) {
        $updateSql .= ", photo = ?";
        $params[] = $nom_photo_db;
    } elseif ($deletePhoto) {
        $updateSql .= ", photo = NULL";
    }

    $updateSql .= " WHERE id = ?";
    $params[] = $stockId;

    $stmt = $pdo->prepare($updateSql);
    $stmt->execute($params);

    // Mise à jour stock_depots (depot_id = 1)
    $stmtDepotCheck = $pdo->prepare("SELECT quantite FROM stock_depots WHERE stock_id = ? AND depot_id = 1");
    $stmtDepotCheck->execute([$stockId]);
    $quantiteDepot = $stmtDepotCheck->fetchColumn();

    if ($quantiteDepot !== false) {
        $nouvelleQuantiteDepot = max(0, (int)$quantiteDepot + $diff);
        $stmtDepotUpdate = $pdo->prepare("UPDATE stock_depots SET quantite = ? WHERE stock_id = ? AND depot_id = 1");
        $stmtDepotUpdate->execute([$nouvelleQuantiteDepot, $stockId]);
    } else {
        $nouvelleQuantiteDepot = max(0, $quantite);
        $stmtDepotInsert = $pdo->prepare("INSERT INTO stock_depots (stock_id, depot_id, quantite) VALUES (?, 1, ?)");
        $stmtDepotInsert->execute([$stockId, $nouvelleQuantiteDepot]);
    }

    // ---- DOCS: suppressions ciblées (DB)
    if (!empty($deleteDocIds)) {
        // Récup pour éventuellement supprimer les fichiers ensuite (hors transaction)
        $in  = implode(',', array_fill(0, count($deleteDocIds), '?'));
        $sql = "SELECT id, chemin_fichier FROM stock_documents WHERE stock_id = ? AND id IN ($in)";
        $paramsSel = array_merge([$stockId], $deleteDocIds);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($paramsSel);
        $toDeleteFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Delete DB
        $sqlDel = "DELETE FROM stock_documents WHERE stock_id = ? AND id IN ($in)";
        $stmt = $pdo->prepare($sqlDel);
        $stmt->execute($paramsSel);

        // Keep IDs for response + delete files after commit
        foreach ($toDeleteFiles as $r) {
            $docsDeleted[] = (int)$r['id'];
        }

        // On supprimera les fichiers en dehors de la transaction, plus bas.
        $filesToUnlink = $toDeleteFiles; // stocke pour après commit
    } else {
        $filesToUnlink = [];
    }

    // ---- DOCS: insert des nouveaux (DB)
    if (!empty($pendingInserts)) {
        $uploadedBy = isset($_SESSION['utilisateurs']['id']) ? (int)$_SESSION['utilisateurs']['id'] : null;
        $ins = $pdo->prepare("
            INSERT INTO stock_documents (stock_id, nom_affichage, chemin_fichier, type_mime, taille, checksum_sha1, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($pendingInserts as $pi) {
            $ins->execute([
                $stockId,
                $pi['nom'],
                $pi['rel'],
                $pi['mime'],
                $pi['size'],
                $pi['sha1'],
                $uploadedBy
            ]);
            $newId = (int)$pdo->lastInsertId();
            $docsAdded[] = [
                'id'   => $newId,
                'nom'  => $pi['nom'],
                'url'  => '/' . ltrim($pi['rel'], '/'),
                'size' => (int)$pi['size']
            ];
        }
    }

    $pdo->commit();

    // Supprimer les fichiers des docs supprimés (en dehors de la transaction)
    foreach ($filesToUnlink as $r) {
        $abs = absFromRel($r['chemin_fichier']);
        if ($abs && is_file($abs)) {
            @unlink($abs);
        }
    }

    // Récup quantite_disponible + photo
    $stmt = $pdo->prepare("SELECT quantite_disponible, photo FROM stock WHERE id = ?");
    $stmt->execute([$stockId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Récup docs multi à jour
    $stmt = $pdo->prepare("SELECT id, nom_affichage, chemin_fichier, taille FROM stock_documents WHERE stock_id = ? ORDER BY id DESC");
    $stmt->execute([$stockId]);
    $docsAll = array_map(function ($r) {
        return [
            'id'   => (int)$r['id'],
            'nom'  => $r['nom_affichage'],
            'url'  => '/' . ltrim($r['chemin_fichier'], '/'),
            'size' => isset($r['taille']) ? (int)$r['taille'] : null
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode([
    'success'            => true,
    'newNom'             => $nom,
    'newQuantiteTotale'  => $quantite,
    'quantiteDispo'      => (int)($row['quantite_disponible'] ?? 0),
    'newPhotoUrl'        => !empty($row['photo']) ? '/' . ltrim($row['photo'], '/') : null,

    // Multi-docs :
    'docsAdded'          => $docsAdded,
    'docsDeleted'        => $docsDeleted,
    'docsAll'            => $docsAll,

    // 👉 Ajoute cet indicateur :
    'photoDeleted'       => (bool)$photoDeletedPhysically,

    // Compat (non utilisé)
    'newDocument'        => null
]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
