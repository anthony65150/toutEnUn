<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/init.php';
header('Content-Type: application/json; charset=utf-8');

try {
  /* ===== Auth ===== */
  if (
    !isset($_SESSION['utilisateurs']) ||
    (($_SESSION['utilisateurs']['fonction'] ?? '') !== 'administrateur')
  ) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Non autorisé']);
    exit;
  }

  /* ===== Contexte entreprise ===== */
  $entrepriseId = (int)($_SESSION['utilisateurs']['entreprise_id'] ?? 0);
  if ($entrepriseId <= 0) {
    throw new Exception("Entreprise invalide en session");
  }

  /* ===== Inputs ===== */
  $empId      = (int)($_POST['emp_id'] ?? 0);
  $date       = $_POST['date'] ?? null;

  // type peut valoir: 'chantier' | 'depot' | 'conges' | 'maladie' | 'rtt'
  $type       = isset($_POST['type']) ? strtolower(trim((string)$_POST['type'])) : 'chantier';
  $chantierId = isset($_POST['chantier_id']) ? (int)$_POST['chantier_id'] : null;
  $depotId    = isset($_POST['depot_id'])    ? (int)$_POST['depot_id']    : null;

  if ($empId <= 0 || !$date) {
    throw new Exception("Paramètres manquants (emp_id, date)");
  }

  // Validation date ISO
  $dt = DateTime::createFromFormat('Y-m-d', $date);
  if (!$dt || $dt->format('Y-m-d') !== $date) {
    throw new Exception("Date invalide (format attendu YYYY-MM-DD)");
  }

  $absenceTypes = ['conges','maladie','rtt'];
  $isAbsence = in_array($type, $absenceTypes, true);

  /* ===== Vérif colonnes ===== */
  $hasTypeCol  = false;
  $hasDepotCol = false;
  try {
    $rs = $pdo->query("SHOW COLUMNS FROM planning_affectations LIKE 'type'");
    $hasTypeCol = $rs && $rs->rowCount() > 0;
  } catch (Throwable $e) {}
  try {
    $rs = $pdo->query("SHOW COLUMNS FROM planning_affectations LIKE 'depot_id'");
    $hasDepotCol = $rs && $rs->rowCount() > 0;
  } catch (Throwable $e) {}

  /* ===== Validation combinatoire =====
     - chantier  : nécessite chantier_id>0, depot_id absent
     - depot     : nécessite depot_id>=0, chantier_id absent
     - absence   : ne nécessite aucun ID (et requiert la colonne type)
  */
  if ($isAbsence) {
    if (!$hasTypeCol) {
      throw new Exception("Le champ 'type' est requis pour enregistrer une absence.");
    }
    // on ignore tout ID transmis par erreur
    $chantierId = null;
    $depotId    = null;

  } elseif ($type === 'depot') {
    $hasDep = is_int($depotId) && $depotId >= 0; // >=0 car dépôt générique autorisé
    if (!$hasDep || (is_int($chantierId) && $chantierId > 0)) {
      throw new Exception("Spécifie uniquement depot_id (>=0) pour une affectation dépôt.");
    }

  } else {
    // défaut: chantier
    $type = 'chantier';
    $hasCh = is_int($chantierId) && $chantierId > 0;
    if (!$hasCh || (is_int($depotId) && $depotId >= 0)) {
      throw new Exception("Spécifie uniquement chantier_id (>0) pour une affectation chantier.");
    }
  }

  /* =======================
     AFFECTATION — CHANTIER
  ======================= */
  if ($type === 'chantier') {
    // Vérif chantier
    $q = $pdo->prepare("SELECT nom FROM chantiers WHERE id = ? AND entreprise_id = ?");
    $q->execute([$chantierId, $entrepriseId]);
    $nomChantier = $q->fetchColumn();
    if (!$nomChantier) {
      throw new Exception("Chantier introuvable pour cette entreprise.");
    }

    if ($hasTypeCol && $hasDepotCol) {
      $sql = "
        INSERT INTO planning_affectations (entreprise_id, utilisateur_id, date_jour, type, chantier_id, depot_id, is_active)
        VALUES (:e, :u, :d, 'chantier', :c, NULL, 1)
        ON DUPLICATE KEY UPDATE
          type='chantier', chantier_id=VALUES(chantier_id), depot_id=NULL, is_active=1
      ";
      $st = $pdo->prepare($sql);
      $st->execute([
        ':e' => $entrepriseId,
        ':u' => $empId,
        ':d' => $date,
        ':c' => $chantierId
      ]);
    } else {
      // Compat ancien schéma
      $sql = "
        INSERT INTO planning_affectations (entreprise_id, utilisateur_id, chantier_id, date_jour, is_active)
        VALUES (:e, :u, :c, :d, 1)
        ON DUPLICATE KEY UPDATE chantier_id=VALUES(chantier_id), is_active=1
      ";
      $st = $pdo->prepare($sql);
      $st->execute([
        ':e' => $entrepriseId,
        ':u' => $empId,
        ':c' => $chantierId,
        ':d' => $date
      ]);
    }

    echo json_encode(['ok' => true, 'type' => 'chantier', 'date' => $date, 'chantier_id' => $chantierId]);
    exit;
  }

  /* =======================
     AFFECTATION — DÉPÔT
  ======================= */
  if ($type === 'depot') {
    $label        = 'Dépôt';
    $depIdToStore = null;

    if ($depotId > 0) {
      // Vérif dépôt réel
      $q = $pdo->prepare("SELECT nom FROM depots WHERE id = ? AND entreprise_id = ?");
      $q->execute([$depotId, $entrepriseId]);
      $nomDepot = $q->fetchColumn();
      if (!$nomDepot) {
        throw new Exception("Dépôt introuvable pour cette entreprise.");
      }
      $label        = "Dépôt — " . $nomDepot;
      $depIdToStore = $depotId;
    }

    if ($hasTypeCol && $hasDepotCol) {
      $sql = "
        INSERT INTO planning_affectations (entreprise_id, utilisateur_id, date_jour, type, chantier_id, depot_id, is_active)
        VALUES (:e, :u, :d, 'depot', NULL, :dep, 1)
        ON DUPLICATE KEY UPDATE
          type='depot', chantier_id=NULL, depot_id=VALUES(depot_id), is_active=1
      ";
      $st = $pdo->prepare($sql);
      $st->execute([
        ':e'   => $entrepriseId,
        ':u'   => $empId,
        ':d'   => $date,
        ':dep' => $depIdToStore
      ]);
    } else {
      // Compat : encode dépôt par chantier_id=0
      $sql = "
        INSERT INTO planning_affectations (entreprise_id, utilisateur_id, chantier_id, date_jour, is_active)
        VALUES (:e, :u, 0, :d, 1)
        ON DUPLICATE KEY UPDATE chantier_id=0, is_active=1
      ";
      $st = $pdo->prepare($sql);
      $st->execute([
        ':e' => $entrepriseId,
        ':u' => $empId,
        ':d' => $date
      ]);
    }

    echo json_encode(['ok' => true, 'type' => 'depot', 'date' => $date, 'depot_id' => $depIdToStore ?? 0, 'label' => $label]);
    exit;
  }

  /* =======================
     AFFECTATION — ABSENCE
     (conges | maladie | rtt)
  ======================= */
  if ($isAbsence) {
    if (!$hasTypeCol) {
      throw new Exception("Impossible d'enregistrer une absence : colonne 'type' manquante.");
    }

    $sql = "
      INSERT INTO planning_affectations (entreprise_id, utilisateur_id, date_jour, type, chantier_id, depot_id, is_active)
      VALUES (:e, :u, :d, :t, NULL, NULL, 1)
      ON DUPLICATE KEY UPDATE
        type=VALUES(type), chantier_id=NULL, depot_id=NULL, is_active=1
    ";
    $st = $pdo->prepare($sql);
    $st->execute([
      ':e' => $entrepriseId,
      ':u' => $empId,
      ':d' => $date,
      ':t' => $type
    ]);

    echo json_encode(['ok' => true, 'type' => $type, 'date' => $date]);
    exit;
  }

  // Type inconnu
  throw new Exception("Type d'affectation invalide.");

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
