// ------- helpers -------
function confirmDelete({ label = 'cet élément' } = {}) {
  return new Promise((resolve) => {
    const el  = document.getElementById('confirmDeleteModal');
    const txt = document.getElementById('confirmDeleteText');
    const btn = document.getElementById('confirmDeleteBtn');

    txt.innerHTML = `Es-tu sûr de vouloir supprimer <b>${label}</b> ? Cette action est irréversible.`;

    const modal = new bootstrap.Modal(el, { backdrop: 'static', keyboard: false });

    const onCancel  = () => { cleanup(); resolve(false); };
    const onConfirm = () => { cleanup(); modal.hide(); resolve(true); };
    const cleanup   = () => {
      el.removeEventListener('hidden.bs.modal', onCancel);
      btn.removeEventListener('click', onConfirm);
    };

    el.addEventListener('hidden.bs.modal', onCancel, { once: true });
    btn.addEventListener('click', onConfirm, { once: true });

    modal.show();
  });
}

(function () {
  const root = document.getElementById('heuresPage');
  if (!root) return;

  const isAdmin    = root.dataset.isAdmin === '1';
  const csrfToken  = root.dataset.csrfToken || '';
  const chantierId = parseInt(root.dataset.chantierId || '0', 10);

 function recalcRow(tr) {
  const qte     = parseFloat(tr.dataset.qte || '0');
  const tuHours = parseFloat(tr.dataset.tuHours || '0');   // TU prévu (h/u)
  const pctRaw  = parseFloat(tr.querySelector('.avc-input')?.value || '0');

  const pct   = Math.max(0, Math.min(100, pctRaw));
  const pct01 = pct / 100;

  const ttH = (qte || 0) * (tuHours || 0);                 // Temps total prévu
  const tsH = ttH * pct01;                                 // Temps au stade (prévu)

  const hpCell = tr.querySelector('.hp-cell');
  const hpH    = parseFloat(hpCell?.dataset.h || '0');     // Heures pointées (réel)

  const ecH   = tsH - hpH;

  // ✅ Nouveau TU = Heures pointées / (Quantité × %avancement)
  const denom = (qte > 0 && pct01 > 0) ? (qte * pct01) : 0;
  const newTU = denom > 0 ? (hpH / denom) : 0;

  const ttCell = tr.querySelector('.tt-cell');
  const tsCell = tr.querySelector('.ts-cell');
  const ecCell = tr.querySelector('.ecart-cell');
  const ntCell = tr.querySelector('.newtu-cell');

  if (ttCell) ttCell.textContent = ttH.toFixed(2);
  if (tsCell) {
    tsCell.dataset.h   = tsH.toFixed(2);
    tsCell.textContent = tsH.toFixed(2);
  }

  // — ÉCART : rouge si < 0, vert sinon
  if (ecCell) {
    ecCell.dataset.h   = ecH.toFixed(2);
    ecCell.textContent = ecH.toFixed(2);
    ecCell.classList.remove('cell-good', 'cell-bad');
    if (ecH < 0) ecCell.classList.add('cell-bad');
    else ecCell.classList.add('cell-good');
  }

  // — NOUVEAU TU : rouge si > TU prévu, vert sinon (si denom == 0, neutre)
  if (ntCell) {
    ntCell.dataset.h   = newTU.toFixed(2);
    ntCell.textContent = newTU.toFixed(2);
    ntCell.classList.remove('cell-good', 'cell-bad');
    if (denom > 0) {
      if (newTU > tuHours + 1e-9) ntCell.classList.add('cell-bad');
      else ntCell.classList.add('cell-good');
    }
  }
}



  // Recalc quand le % avancement change
  document.getElementById('heuresTbody')?.addEventListener('input', (e) => {
    if (e.target.matches('.avc-input')) {
      const tr = e.target.closest('tr');
      if (tr) recalcRow(tr);
    }
  });

  // SAVE (💾) — envoie tu_heures tel quel
  document.getElementById('heuresTbody')?.addEventListener('click', async (e) => {
  const btn = e.target.closest('.save-row');
  if (!btn) return;          // ne réagit plus au bouton .edit-row
  if (!isAdmin) return;

  const tr  = btn.closest('tr');
  const id  = tr?.dataset.id;
  if (!id) return;

  const qte     = parseFloat(tr.dataset.qte || '0');
  const tuHours = parseFloat(tr.dataset.tuHours || '0');
  const pct     = parseFloat(tr.querySelector('.avc-input')?.value || '0');

  try {
    const res = await fetch('./ajax/chantier_heures_save.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept':'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        csrf_token: csrfToken,
        tache_id: parseInt(id, 10),
        chantier_id: chantierId,
        quantite: qte,
        tu_heures: tuHours,
        avancement_pct: pct
      })
    });

    const raw = await res.text();       // ← lit le brut pour éviter le “<”
    let j; try { j = JSON.parse(raw); } catch { throw new Error(raw.slice(0,800)); }
    if (!res.ok || !j?.success) throw new Error(j?.message || 'Échec sauvegarde');

    tr.classList.add('table-success');
    setTimeout(() => tr.classList.remove('table-success'), 1200);
  } catch (err) {
    console.error(err);
    tr.classList.add('table-danger');
    setTimeout(() => tr.classList.remove('table-danger'), 1200);
    alert('Sauvegarde impossible : ' + (err.message || err));
  }
});


  // DELETE (🗑️) avec modale
  document.getElementById('heuresTbody')?.addEventListener('click', async (e) => {
    if (!e.target.closest('.delete-row')) return;
    if (!isAdmin) return;

    const tr  = e.target.closest('tr');
    const id  = tr?.dataset.id;
    const nom = tr?.dataset.name || 'cette tâche';
    if (!id) return;

    const ok = await confirmDelete({ label: nom });
    if (!ok) return;

    try {
      const res = await fetch('./ajax/chantier_heures_delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          csrf_token: csrfToken,
          tache_id: parseInt(id, 10),
          chantier_id: chantierId
        })
      });

      const text = await res.text();
      let j;
      try { j = JSON.parse(text); } catch { j = null; }
      if (!res.ok || !j || !j.success) {
        const msg = (j && j.message) ? j.message : `HTTP ${res.status} – ${text}`;
        throw new Error(msg);
      }

      tr.remove();
    } catch (err) {
      console.error(err);
      alert('Suppression impossible : ' + (err.message || err));
    }
  });

  // Crée / met à jour une tâche (modale) — TU en heures
  const form = document.getElementById('tacheForm');
  if (isAdmin && form) {
    form.addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const payload = Object.fromEntries(fd.entries());

  // ✅ contexte requis
  payload.csrf_token  = csrfToken;
  payload.chantier_id = chantierId;
  payload.tache_id    = parseInt(payload.tache_id || '0', 10);

  // ✅ MAPPINGS des noms attendus par le PHP
  // task_name -> nom
  if (!('nom' in payload) && ('task_name' in payload)) {
    payload.nom = (payload.task_name || '').trim();
  }
  // task_shortcut -> shortcut (tu l'avais déjà)
  if (!('shortcut' in payload) && ('task_shortcut' in payload)) {
    payload.shortcut = (payload.task_shortcut || '').trim();
  }

  // ✅ Normalisations
  payload.nom = (payload.nom || '').trim();
  payload.shortcut = (payload.shortcut || '').trim();

  // ✅ Nombres (gère la virgule française)
  const toNum = v => parseFloat(String(v).replace(',', '.')) || 0;
  payload.quantite       = toNum(payload.quantite);
  payload.tu_heures      = toNum(payload.tu_heures);
  payload.avancement_pct = 0;

  try {
  const res = await fetch('./ajax/chantier_tache_upsert.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
    credentials: 'same-origin',               // <-- important pour la session/CSRF
    body: JSON.stringify(payload)
  });

  const raw = await res.text();               // <-- on lit BRUT
  console.log('[CREATE raw]', raw);           // <-- si PHP crashe, tu le vois ici
  let j;
  try { j = JSON.parse(raw); }
  catch { throw new Error(raw.slice(0, 800)); }

  if (!res.ok || !j?.success) throw new Error(j?.message || 'Échec enregistrement');

  location.reload();
} catch (err) {
  alert('Erreur: ' + (err.message || err));
}

});


  }
  // Recalcul initial de toutes les lignes au chargement
document.querySelectorAll('#heuresTbody tr').forEach(tr => recalcRow(tr));

})();




// modal modifier
(() => {
  const tbody = document.getElementById('heuresTbody');
  if (!tbody) return;

  // URL de mise à jour (à adapter si besoin)
  const UPDATE_URL = '/chantiers/taches_update.php';

  // helpers
  const fmt2 = (n) => Number(n || 0).toFixed(2);

  // refs modale
  const modalEl = document.getElementById('tacheEditModal');
  const modal   = new bootstrap.Modal(modalEl);
  const form    = document.getElementById('tacheEditForm');

  const f = {
    id:  document.getElementById('editTacheId'),
    nom: document.getElementById('editTaskName'),
    sh:  document.getElementById('editTaskShortcut'),
    u:   document.getElementById('editTacheUnite'),
    qte: document.getElementById('editTacheQte'),
    tu:  document.getElementById('editTacheTUh'),
  };

  let currentTr = null;

  // Ouvrir la modale sur clic du bouton "modifier" (jaune)
  tbody.addEventListener('click', (e) => {
    const btn = e.target.closest('.edit-row');
    if (!btn) return;

    currentTr = btn.closest('tr');
    if (!currentTr) return;

    const id   = currentTr.dataset.id || '';
    const nom  = currentTr.dataset.name || '';
    const sh   = currentTr.dataset.shortcut || '';
    const u    = currentTr.dataset.unite || '';

    // quantité & unité: on a tout en data-*, donc on prend direct
    const qte  = currentTr.dataset.qte || '0';
    const tu   = currentTr.dataset.tuHours || '0';

    f.id.value  = id;
    f.nom.value = nom;
    f.sh.value  = sh;
    f.u.value   = u;
    f.qte.value = qte;
    f.tu.value  = tu;

    modal.show();
  });

  // Soumission (enregistrer la modif)
form.addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!currentTr) return;

  const UPDATE_URL = './ajax/chantier_tache_upsert.php'; // ✅ même endpoint que la création

  const payload = {
    csrf_token:  form.querySelector('[name="csrf_token"]').value,
    chantier_id: form.querySelector('[name="chantier_id"]').value,
    tache_id:    parseInt(document.getElementById('editTacheId').value || '0', 10),

    // mêmes noms que le PHP attend :
    nom:        document.getElementById('editTaskName').value.trim(),
    shortcut:   document.getElementById('editTaskShortcut').value.trim(),
    unite:      document.getElementById('editTacheUnite').value.trim(),
    quantite:   parseFloat((document.getElementById('editTacheQte').value || '0').replace(',', '.')) || 0,
    tu_heures:  parseFloat((document.getElementById('editTacheTUh').value || '0').replace(',', '.')) || 0
  };

  let data;
  try {
    const res = await fetch(UPDATE_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(payload)
    });

    const raw = await res.text();        // ← on lit BRUT
    console.log('[EDIT raw]', raw);      // ← tu verras l’erreur PHP exacte ici
    try { data = JSON.parse(raw); }
    catch { throw new Error(raw.slice(0, 800)); }

    if (!res.ok || data?.success === false) {
      throw new Error(data?.message || 'Échec enregistrement');
    }
  } catch (err) {
    alert("Sauvegarde impossible : " + (err?.message || String(err)));
    return;
  }

  // --- si on arrive ici : succès → MAJ DOM + recalculs ---
  const qte = parseFloat(f.qte.value) || 0;
  const tu  = parseFloat(f.tu.value) || 0;

  currentTr.dataset.name     = f.nom.value;
  currentTr.dataset.shortcut = f.sh.value;
  currentTr.dataset.unite    = f.u.value;
  currentTr.dataset.qte      = String(qte);
  currentTr.dataset.tuHours  = String(tu);

  const nameDiv = currentTr.querySelector('td:first-child .fw-semibold');
  if (nameDiv) nameDiv.textContent = f.nom.value;

  const fmt2 = (n) => Number(n || 0).toFixed(2);

  const qteTd = currentTr.children[1];
  if (qteTd) qteTd.textContent = fmt2(qte) + (f.u.value ? (' ' + f.u.value) : '');

  const tuTd = currentTr.children[2];
  if (tuTd) tuTd.textContent = fmt2(tu);

  const tt = qte * tu;
  const pctInput = currentTr.querySelector('.avc-input');
  const pct = Math.min(100, Math.max(0, parseFloat(pctInput?.value || '0')));
  const ts = tt * (pct / 100);

  const hpCell = currentTr.querySelector('.hp-cell');
  const hp = parseFloat(hpCell?.dataset.h || '0') || 0;
  const ec = ts - hp;
  const newTU = (qte > 0 && pct > 0) ? (hp / (qte * (pct / 100))) : 0;

  const ttTd = currentTr.querySelector('.tt-cell');
  if (ttTd) ttTd.textContent = fmt2(tt);

  const tsTd = currentTr.querySelector('.ts-cell');
  if (tsTd) { tsTd.dataset.h = fmt2(ts); tsTd.textContent = fmt2(ts); }

  const ecTd = currentTr.querySelector('.ecart-cell');
  if (ecTd) {
    ecTd.dataset.h = fmt2(ec);
    ecTd.textContent = fmt2(ec);
    ecTd.classList.remove('cell-good','cell-bad');
    ecTd.classList.add(ec < 0 ? 'cell-bad' : 'cell-good');
  }

  const newTd = currentTr.querySelector('.newtu-cell');
  if (newTd) {
    newTd.dataset.h = fmt2(newTU);
    newTd.textContent = fmt2(newTU);
    newTd.classList.remove('cell-good','cell-bad');
    newTd.classList.add((newTU > tu + 1e-9) ? 'cell-bad' : 'cell-good');
  }

  currentTr.classList.add('table-success');
  setTimeout(() => currentTr.classList.remove('table-success'), 1200);
  modal.hide();
});})();
