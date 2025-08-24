// assets/js/pointage.js
// Nécessite Bootstrap 5 (dropdown + modal + toast) déjà chargé par tes templates.
// Pointage ultra-rapide: 1 clic = cas standard, 2 clics max = cas particulier.

(function(){
  const app = document.getElementById('pointageApp');
  if (!app) return;

  const endpoint   = app.dataset.endpoint || 'pointage_actions.php';
  const role       = app.dataset.role || '';
  const table      = document.getElementById('pointageTable');
  const bulkBar    = document.getElementById('bulkBar');
  const bulkCount  = document.getElementById('bulkCount');
  const search     = document.getElementById('searchInput');
  const dateInput  = document.getElementById('dateInput');

  const filterWrap = document.getElementById('chantierFilters');
  const rows       = () => Array.from(document.querySelectorAll('#pointageTable tbody tr'));

  // Active chantier
  let activeChantier = 'all';
  if (typeof window.defaultChantierId === 'number' && !Number.isNaN(window.defaultChantierId)) {
    // Marque le bouton si présent
    const pre = filterWrap?.querySelector(`[data-chantier="${window.defaultChantierId}"]`);
    if (pre) {
      filterWrap.querySelectorAll('button').forEach(b=>b.classList.remove('active'));
      pre.classList.add('active');
      activeChantier = String(window.defaultChantierId);
    }
  }

  // --- Sélection groupée ---
  const selected = new Set();
  function refreshBulkCount(){
    bulkCount.textContent = `${selected.size} sélectionné${selected.size>1?'s':''}`;
  }

  // --- Filtres ---
  function matchesChantier(row, chantierId){
    if (chantierId === 'all') return true;
    const list = (row.dataset.chantiers || '').split(',').filter(Boolean);
    return list.includes(String(chantierId));
  }
  function matchesSearch(row, q){
    if (!q) return true;
    const name = row.dataset.name || '';
    return name.includes(q);
  }
  function applyFilters(){
    const q = (search?.value || '').trim().toLowerCase();
    rows().forEach(row=>{
      const ok = matchesChantier(row, activeChantier) && matchesSearch(row, q);
      row.style.display = ok ? '' : 'none';
    });
  }

  filterWrap?.addEventListener('click', (e)=>{
    const btn = e.target.closest('button[data-chantier]');
    if(!btn) return;
    filterWrap.querySelectorAll('button[data-chantier]').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    activeChantier = btn.dataset.chantier; // 'all' ou id
    applyFilters();
  });

  search?.addEventListener('input', applyFilters);
  applyFilters();

  // --- Toast Bootstrap minimal ---
  const toastWrap = document.createElement('div');
  toastWrap.innerHTML = `
    <div class="toast align-items-center text-bg-success border-0 position-fixed bottom-0 end-0 m-3" role="alert">
      <div class="d-flex">
        <div class="toast-body">OK</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
      </div>
    </div>`;
  document.body.appendChild(toastWrap);
  const toastEl = toastWrap.querySelector('.toast');
  const toastObj = new bootstrap.Toast(toastEl, { delay: 2000 });
  function showToast(msg, ok=true){
    toastEl.classList.remove('text-bg-success','text-bg-danger');
    toastEl.classList.add(ok?'text-bg-success':'text-bg-danger');
    toastEl.querySelector('.toast-body').textContent = msg;
    toastObj.show();
  }

  // --- Modal "Autre…" ---
  const autreModalEl  = document.getElementById('autreModal');
  const autreForm     = document.getElementById('autreForm');
  const autreComment  = document.getElementById('autreComment');
  const autreModal    = new bootstrap.Modal(autreModalEl);
  let pendingAutre = null; // { payload, rows }

  autreForm?.addEventListener('submit', async (e)=>{
    e.preventDefault();
    if (!pendingAutre) return;
    pendingAutre.payload.comment = (autreComment.value || '').trim();
    if (!pendingAutre.payload.comment) return;
    await sendPointage(pendingAutre.payload, pendingAutre.rows);
    pendingAutre = null;
    autreForm.reset();
    autreModal.hide();
  });

  // --- Helpers payload ---
  function buildPayload(userIds, data){
    const action  = data.action || data.bulk;
    const isAutre = data.reason === 'autre';
    const dateISO = dateInput?.value || new Date().toISOString().slice(0,10);
    const chantier_id = (activeChantier !== 'all') ? Number(activeChantier) : null;

    const base = {
      date: dateISO,
      chantier_id,
      items: userIds.map(id => ({
        user_id: Number(id),
        action,                           // 'present' | 'absence' | 'conduite'
        hours: data.hours ? Number(data.hours) : null,
        reason: data.reason || null,      // 'maladie' | 'conges' | 'sans_solde' | 'autre'
      })),
      comment: null
    };

    if (isAutre) {
      // Ajoute les lignes correspondantes
      const tableRows = userIds.map(id => document.querySelector(`tr[data-user-id="${id}"]`)).filter(Boolean);
      pendingAutre = { payload: base, rows: tableRows };
      autreModal.show();
    }
    return base;
  }

  async function sendPointage(payload, rowElems){
    // Si on attend un commentaire pour "autre", ne pas envoyer
    const hasAutreAwaiting = payload.items.some(it => it.reason === 'autre') && !payload.comment;
    if (hasAutreAwaiting) return;

    try {
      const res = await fetch(endpoint, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
      });
      const json = await res.json();

      if (json?.success) {
        // surlignage vert + désélection
        (rowElems || []).forEach(tr=>{
          tr.classList.add('table-success');
          setTimeout(()=> tr.classList.remove('table-success'), 1200);
          const cb = tr.querySelector('.select-user');
          if (cb?.checked) {
            cb.checked = false;
            selected.delete(tr.dataset.userId);
          }
        });
        refreshBulkCount();
        showToast(json.message || 'Pointage enregistré.');
      } else {
        showToast(json?.message || 'Erreur inconnue', false);
      }
    } catch (err) {
      showToast('Erreur réseau', false);
    }
  }

  // --- Actions ligne (quick-action) ---
  table?.addEventListener('click', async (e)=>{
    const btn = e.target.closest('.quick-action');
    if (!btn) return;
    const tr = btn.closest('tr');
    const userId = tr?.dataset.userId;
    if (!userId) return;
    const payload = buildPayload([userId], btn.dataset);
    await sendPointage(payload, [tr]);
  });

  // --- Sélection groupée ---
  table?.addEventListener('change', (e)=>{
    if (!e.target.classList.contains('select-user')) return;
    const tr = e.target.closest('tr');
    const uid = tr?.dataset.userId;
    if (!uid) return;
    if (e.target.checked) selected.add(uid);
    else selected.delete(uid);
    refreshBulkCount();
  });

  // --- Actions groupées ---
  bulkBar?.addEventListener('click', async (e)=>{
    const el = e.target.closest('[data-bulk]');
    if (!el || selected.size === 0) return;
    const userIds = Array.from(selected);
    const payload = buildPayload(userIds, el.dataset);
    const affectedRows = userIds.map(id => document.querySelector(`tr[data-user-id="${id}"]`)).filter(Boolean);
    await sendPointage(payload, affectedRows);
  });

  // Raccourcis (optionnels)
  document.addEventListener('keydown', (e)=>{
    const visibleRows = rows().filter(r => r.style.display !== 'none');
    if (!visibleRows.length) return;
    // P = Présent 7h
    if (e.key.toLowerCase() === 'p') {
      const ids = visibleRows.map(r => r.dataset.userId);
      const payload = buildPayload(ids, { bulk: 'present', hours: '7' });
      sendPointage(payload, visibleRows);
    }
    // A = Abs. Maladie
    if (e.key.toLowerCase() === 'a') {
      const ids = visibleRows.map(r => r.dataset.userId);
      const payload = buildPayload(ids, { bulk: 'absence', reason: 'maladie' });
      sendPointage(payload, visibleRows);
    }
    // C = Conduite 2h
    if (e.key.toLowerCase() === 'c') {
      const ids = visibleRows.map(r => r.dataset.userId);
      const payload = buildPayload(ids, { bulk: 'conduite', hours: '2' });
      sendPointage(payload, visibleRows);
    }
  });
})();
