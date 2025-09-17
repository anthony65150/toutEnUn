// /pointage/js/pointage.js
document.addEventListener('DOMContentLoaded', () => {
  "use strict";

  /* ==============================
   *   RÉFÉRENCES DOM (scopées)
   * ============================== */
  const app            = document.getElementById('pointageApp');
  if (!app) return;

  const table          = app.querySelector('table');
  const thead          = table?.querySelector('thead');
  const searchInput    = document.getElementById('searchInput');   // peut être hors #pointageApp
  const filtersBar     = document.getElementById('chantierFilters');
  const camionControls = document.getElementById('camionControls');

  // Modal Absence
  const absenceModalEl = document.getElementById('absenceModal');
  const absenceModal   = absenceModalEl ? new bootstrap.Modal(absenceModalEl) : null;
  const absForm        = document.getElementById('absenceForm');
  const absSaveBtn     = document.getElementById('absenceSave');

  /* ==============================
   *   HELPERS GÉNÉRAUX
   * ============================== */
  const DEBUG = true; // passe à false en prod

  const labelForReason = (r) => (r === 'conges' ? 'Congés' : (r === 'maladie' ? 'Maladie' : 'Injustifié'));

  const showError = (msg, ctx) => {
    alert(msg || 'Erreur serveur');
    if (DEBUG && ctx) console.error('[POINTAGE]', msg, ctx);
  };

  // YYYY-MM-DD local (sans décalage UTC)
  function todayLocalISO() {
    const d = new Date();
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 10);
  }
  const todayIso = todayLocalISO();

  const asIntOrNull = (v) => {
    const n = parseInt(v, 10);
    return Number.isFinite(n) && n > 0 ? n : null;
  };

  // POST x-www-form-urlencoded → JSON {success:true,...}
  // ❗ ne fait pas d’alert ici (laisse l’appelant décider)
  async function postForm(url, payload) {
    const body = new URLSearchParams();
    Object.entries(payload || {}).forEach(([k, v]) => body.append(k, v == null ? '' : String(v)));

    const res  = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
    const text = await res.text();

    let data = null;
    try { data = JSON.parse(text); } catch { data = null; }
    if (data === 'ok') data = { success: true }; // tolère "ok" simple

    if (!res.ok || !data || data.success === false) {
      const msg = (data && (data.message || data.msg)) || `HTTP ${res?.status || 0}`;
      const err = new Error(msg);
      err.debug = { url, payload, status: res?.status, responseText: text, data };
      if (DEBUG) console.error('POST error', err.debug);
      throw err;
    }
    return data;
  }

  // 8.25 -> "8h15", 4 -> "4h00"
function formatHM(dec){
  const h = Math.floor(dec);
  const m = Math.round((dec - h) * 60);
  return `${h}h${String(m).padStart(2,'0')}`;
}
const FULL_DAY = 8.25; // 8h15

function resolveCellChantierId(td){
  // 1) sélection explicite sur la cellule ?
  const sel = td?.dataset?.selectedChantierId ? parseInt(td.dataset.selectedChantierId,10) : null;
  if (Number.isFinite(sel) && sel > 0) return sel;

  // 2) chantier filtré actif ?
  const bar = document.getElementById('chantierFilters');
  const btn = bar?.querySelector('button.active[data-chantier]');
  const cid = btn ? parseInt(btn.dataset.chantier,10) : 0;
  if (cid > 0) return cid;

  // 3) un seul chantier planifié dans la cellule ?
  const planned = (td?.dataset?.plannedChantiersDay || '').split(',').filter(Boolean);
  if (planned.length === 1) {
    const only = parseInt(planned[0],10);
    if (Number.isFinite(only) && only > 0) return only;
  }

  return null; // non résolu
}



  /* ==============================
   *   ENTÊTE / JOURS VISIBLES
   * ============================== */
  const headerThs = Array.from(thead?.querySelectorAll('tr th[data-iso]') || []);
  const dayIsos   = headerThs.map(th => th.dataset.iso);
  let activeDay   = dayIsos.includes(todayIso) ? todayIso : null; // pas de fallback si hors semaine

  function getActiveColIndex() {
    if (!activeDay) return -1;
    const i = dayIsos.indexOf(activeDay);
    return i === -1 ? -1 : (i + 1); // +1 car col 0 = noms employés
  }

  function highlightActiveDay() {
    document.querySelectorAll('.day-active').forEach(el => el.classList.remove('day-active'));
    const col = getActiveColIndex();
    if (col < 0) return;
    const th = thead?.querySelectorAll('tr th')[col];
    if (th) th.classList.add('day-active');
    document.querySelectorAll('#pointageApp tbody tr').forEach(tr => {
      const td = tr.querySelectorAll('td')[col];
      if (td) td.classList.add('day-active');
    });
  }

  thead?.addEventListener('click', (e) => {
    const th = e.target.closest('th[data-iso]');
    if (!th) return;
    activeDay = th.dataset.iso || null;
    if (activeChantier !== 'all') {
      updateTruckUIFromActiveFilter();
    } else if (camionControls) {
      camionControls.innerHTML = '';
    }
    applyFilter();
  });

  /* ==============================
   *   FILTRE CHANTIERS + RECHERCHE
   * ============================== */
  let activeChantier = (() => {
    const btn = filtersBar?.querySelector('button.active[data-chantier],button.active[data-chantier-id]');
    const appRole = (app.dataset.role || '').toLowerCase();
if (appRole !== 'administrateur') {
  const title = document.getElementById('pageTitle');
  if (title && btn.textContent) {
    title.textContent = 'Pointage ' + btn.textContent.trim();
  }
}
    if (btn?.dataset.chantier)   return btn.dataset.chantier;
    if (btn?.dataset.chantierId) return btn.dataset.chantierId;
    return 'all';
  })();

  let query = '';

  function rowMatches(tr) {
    if (activeChantier !== 'all' && activeDay) {
      const cell = tr.querySelector(`td[data-date="${activeDay}"]`);
      if (!cell) return false;
      const planned = (cell.dataset.plannedChantiersDay || '').split(',').filter(Boolean);
      if (!planned.includes(String(activeChantier))) return false;
    }
    if (query) {
      const name = (tr.dataset.name || '').toLowerCase();
      if (!name.includes(query)) return false;
    }
    return true;
  }

  function applyFilter() {
    highlightActiveDay();
    document.querySelectorAll('#pointageApp tbody tr[data-user-id]').forEach(tr => {
      tr.classList.toggle('d-none', !rowMatches(tr));
    });
  }

  searchInput?.addEventListener('input', () => {
    query = (searchInput.value || '').toLowerCase();
    applyFilter();
  });

  filtersBar?.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-chantier],button[data-chantier-id]');
    if (!btn) return;

    filtersBar.querySelectorAll('button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    activeChantier = btn.dataset.chantier ?? btn.dataset.chantierId ?? 'all';

    // maj URL sans rechargement
    try {
      const url = new URL(window.location.href);
      if (!activeChantier || activeChantier === 'all' || activeChantier === '0') {
        url.searchParams.delete('chantier_id');
      } else {
        url.searchParams.set('chantier_id', String(parseInt(activeChantier, 10)));
      }
      history.replaceState(null, '', url);
    } catch {}

    if (activeChantier !== 'all') {
      updateTruckUIFromActiveFilter();
    } else if (camionControls) {
      camionControls.innerHTML = '';
    }

    applyFilter();
  });

  /* ==============================
   *   SÉLECTEUR CAMIONS (+/−)
   * ============================== */
  const DAYS = Array.isArray(window.POINTAGE_DAYS) ? window.POINTAGE_DAYS : [];
  if (typeof window.truckCap === 'undefined') window.truckCap = new Map();
  const truckCap = window.truckCap;
  const capKey   = (c,d)=>`${c}|${d}`;
  const getCap   = (c,d)=> (truckCap.get(capKey(c,d)) ?? 1);

  async function fetchCfg(chantierId){
    const r = await fetch(`${window.API_CAMIONS_CFG}?chantier_id=${encodeURIComponent(chantierId)}`,{credentials:'same-origin'});
    const j = await r.json(); if(!j.ok) throw new Error(j.message||'GET cfg');
    return Number(j.nb)||1;
  }
  async function saveCfg(chantierId, nb){
    const body = new URLSearchParams({chantier_id:String(chantierId), nb:String(nb)});
    const r = await fetch(window.API_CAMIONS_CFG,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},credentials:'same-origin',body});
    const j = await r.json(); if(!j.ok) throw new Error(j.message||'POST cfg');
    return Number(j.nb)||1;
  }
  function applyCfgToWeek(chantierId, nb){
  DAYS.forEach(d=> truckCap.set(capKey(chantierId,d), Math.max(1, Number(nb)||1)));
}

  // UI stepper (sans nom de chantier)
  function renderTruckStepper(chantierId, nbInit){
    const host = document.getElementById('camionControls');
    if(!host) return;
    host.innerHTML = '';
    if(!chantierId || chantierId==='all') return;

    host.insertAdjacentHTML('afterbegin', `
      <label for="camionCount" class="mb-0 small text-muted me-2">Nombre de camions</label>
      <div class="input-group input-group-sm camion-stepper" style="width:140px">
        <button class="btn btn-outline-secondary" type="button" data-action="decr" aria-label="Diminuer">−</button>
        <input id="camionCount" type="text" class="form-control text-center"
               value="${nbInit}" inputmode="numeric" pattern="[0-9]*" aria-label="Nombre de camions">
        <button class="btn btn-outline-secondary" type="button" data-action="incr" aria-label="Augmenter">+</button>
      </div>
    `);

    const stepper = host.querySelector('.camion-stepper');
    const input   = host.querySelector('#camionCount');

    let t;
    function commit(n, emit=true){
      const v = Math.max(1, Math.min(5, parseInt(n,10)||1));

      input.value = String(v);
      applyCfgToWeek(chantierId, v);
      if (emit){
        clearTimeout(t);
        t = setTimeout(()=> saveCfg(chantierId, v).catch(console.error), 300);
        document.dispatchEvent(new CustomEvent('camion:change', { detail: { value: v, chantierId }}));
      }
    }

    stepper.addEventListener('click', (e)=>{
      const btn = e.target.closest('button[data-action]'); if(!btn) return;
      const cur = parseInt(input.value,10)||0;
      commit(cur + (btn.dataset.action==='incr'? 1 : -1));
    });

    input.addEventListener('input', ()=>{ input.value = input.value.replace(/\D+/g,''); });
    input.addEventListener('change', ()=> commit(input.value));
    input.addEventListener('keydown', (e)=>{ if(e.key==='Enter'){ e.preventDefault(); input.blur(); } });

    commit(nbInit, false);
  }

  async function updateTruckUIFromActiveFilter(){
    const bar = document.getElementById('chantierFilters'); if(!bar) return;
    const btn = bar.querySelector('button.active[data-chantier]');
    const cid = btn ? parseInt(btn.dataset.chantier,10) : 0;
    const host= document.getElementById('camionControls');
    if(!cid){ if(host) host.innerHTML=''; return; }
    try { const nb = await fetchCfg(cid); renderTruckStepper(cid, nb); }
    catch(e){ console.error(e); renderTruckStepper(cid, 1); }
  }

  document.getElementById('chantierFilters')?.addEventListener('click', (e)=>{
    if(!e.target.closest('[data-chantier]')) return;
    updateTruckUIFromActiveFilter();
  });

  // init bouton actif
  (function(){
    const b=document.querySelector('#chantierFilters .active[data-chantier]');
    if(b) updateTruckUIFromActiveFilter();
  })();

  /* ==============================
 *   PRÉSENCE (toggle 8h15)
 * ============================== */
table?.addEventListener('click', async (e) => {
  const btn = e.target.closest('.present-btn');
  if (!btn) return;

  const td      = btn.closest('td');
  const tr      = btn.closest('tr');
  const userId  = tr?.dataset.userId;
  const dateIso = td?.dataset.date;
  if (!userId || !dateIso) return;

  const hours    = btn.dataset.hours || '8.25';
  const isActive = btn.classList.contains('btn-success');
  const chantierId = resolveCellChantierId(td);

  try {
    if (isActive) {
      // -> OFF
      const payload = { utilisateur_id: userId, date: dateIso, hours: '0' };
      if (chantierId) payload.chantier_id = chantierId;
      await postForm('/pointage/pointage_present.php', payload);

      btn.classList.remove('btn-success');
      btn.classList.add('btn-outline-success');
      btn.textContent = 'Présent 8h15';
      return;
    }

    // -> ON : retirer absence éventuelle
    const del = { utilisateur_id: userId, date: dateIso, remove: '1' };
    if (chantierId) del.chantier_id = chantierId;
    try { await postForm('/pointage/pointage_absence.php', del); } catch {}

    // reset UI absence
    const absBtn = td.querySelector('.absence-btn');
    if (absBtn) {
      absBtn.classList.remove('btn-danger');
      absBtn.classList.add('btn-outline-danger');
      absBtn.textContent = 'Abs.';
    }
    td.querySelector('.absence-pill')?.remove();
    td.querySelectorAll('.conduite-btn').forEach(b => b.disabled = false);

    // poser présence
    const payload = { utilisateur_id: userId, date: dateIso, hours };
    if (chantierId) payload.chantier_id = chantierId;
    await postForm('/pointage/pointage_present.php', payload);

    btn.classList.remove('btn-outline-success');
    btn.classList.add('btn-success');
    btn.textContent = 'Présent 8h15';

  } catch (err) {
    showError(err.message, err.debug);
  }
});


  /* ==============================
 *   ABSENCE (modal)
 * ============================== */
table?.addEventListener('click', (e) => {
  const trigger = e.target.closest('.absence-btn');
  if (!trigger) return;
  const td = trigger.closest('td');
  const tr = trigger.closest('tr');
  if (!td || !tr) return;

  if (absenceModalEl) (absenceModalEl)._targetCell = td;

  const uid = tr.dataset.userId || '';
  const d   = td.dataset.date || '';
  const fUser = document.getElementById('absUserId');
  const fDate = document.getElementById('absDate');
  const fHours= document.getElementById('absHours');

  if (fUser)  fUser.value = uid;
  if (fDate)  fDate.value = d;
  if (absForm?.reason) absForm.reason.value = 'conges';
  if (fHours) fHours.value = '8.25';

  absenceModal?.show();
});

absSaveBtn?.addEventListener('click', async () => {
  if (!absenceModalEl?._targetCell) return;

  const td      = absenceModalEl._targetCell;
  const userId  = document.getElementById('absUserId')?.value || '';
  const dateIso = document.getElementById('absDate')?.value  || '';
  const hours   = parseFloat(document.getElementById('absHours')?.value || '0');
  const reason  = absForm?.reason?.value || 'injustifie';

  if (!userId || !dateIso) return;
  if (!hours || hours < 0.25 || hours > 8.25) { showError('Saisis un nombre d’heures valide (0.25 à 8.25).'); return; }

  const chantierId = resolveCellChantierId(td);

  try {
    // 1) Enregistrer l'absence
    const payloadAbs = { utilisateur_id: userId, date: dateIso, reason, hours: String(hours) };
    if (chantierId) payloadAbs.chantier_id = chantierId;
    await postForm('/pointage/pointage_absence.php', payloadAbs);

    // 2) Mettre à jour le SEUL bouton rouge
    const absBtn = td.querySelector('.absence-btn');
    if (absBtn) {
      absBtn.classList.remove('btn-outline-danger');
      absBtn.classList.add('btn-danger');
      absBtn.textContent = `${labelForReason(reason)} ${hours.toString().replace('.', ',')} h`;
    }
    // on ne crée plus de pastille
    td.querySelector('.absence-pill')?.remove();

    // 3) Complément de présence
    const remaining = Math.max(0, FULL_DAY - hours);
    const presentBtn = td.querySelector('.present-btn');

    const payloadPres = { utilisateur_id: userId, date: dateIso, hours: String(remaining) };
    if (chantierId) payloadPres.chantier_id = chantierId;
    await postForm('/pointage/pointage_present.php', payloadPres);

    if (presentBtn) {
      if (remaining > 0) {
        presentBtn.classList.remove('btn-outline-success');
        presentBtn.classList.add('btn-success');
        presentBtn.dataset.hours = String(remaining);
        presentBtn.textContent = 'Présent ' + formatHM(remaining);
      } else {
        presentBtn.classList.remove('btn-success');
        presentBtn.classList.add('btn-outline-success');
        presentBtn.dataset.hours = String(FULL_DAY);
        presentBtn.textContent = 'Présent ' + formatHM(FULL_DAY);
      }
    }

    // 4) Conduite désactivée si absence
    td.querySelectorAll('.conduite-btn').forEach(b => b.disabled = true);

    absenceModal?.hide();
  } catch (err) {
    showError(err.message, err.debug);
  }
});


// retirer une absence existante
table?.addEventListener('click', async (e) => {
  const btn = e.target.closest('.absence-btn');
  if (!btn || !btn.classList.contains('btn-danger')) return;

  const td      = btn.closest('td');
  const tr      = btn.closest('tr');
  const userId  = tr?.dataset.userId;
  const dateIso = td?.dataset.date;
  if (!userId || !dateIso) return;

  const chantierId = resolveCellChantierId(td);

  try {
    const payloadDel = { utilisateur_id: userId, date: dateIso, remove: '1' };
    if (chantierId) payloadDel.chantier_id = chantierId;
    await postForm('/pointage/pointage_absence.php', payloadDel);

    btn.classList.remove('btn-danger');
    btn.classList.add('btn-outline-danger');
    btn.textContent = 'Abs.';   // libellé par défaut
    td.querySelector('.absence-pill')?.remove();
    td.querySelectorAll('.conduite-btn').forEach(b => b.disabled = false);
  } catch (err) {
    showError(err.message, err.debug);
  }
});


  /* ==============================
   *   CONDUITE A/R (capacité)
   * ============================== */
  table?.addEventListener('click', async (e) => {
    const btn = e.target.closest('.conduite-btn');
    if (!btn || btn.disabled) return;

    const td      = btn.closest('td');
    const tr      = btn.closest('tr');
    const userId  = tr?.dataset.userId;
    const dateIso = td?.dataset.date;
    const type    = btn?.dataset.type; // 'A' | 'R'
    if (!userId || !dateIso || !type) return;

    const chantierId = resolveCellChantierId(td);
if (!chantierId) {
  showError("Ambiguïté chantier : sélectionne un chantier (bouton de filtre) ou définis-le sur la cellule.");
  return;
}


    const cap = getCap(chantierId, dateIso);

    // combien déjà actifs pour ce chantier & jour ?
    function countActive(dir, cid, dIso){
      let n = 0;
      document.querySelectorAll(`#pointageApp tbody td[data-date="${dIso}"]`).forEach(cell=>{
        const chs = (cell.dataset.plannedChantiersDay || '').split(',').filter(Boolean);
        if (chs.includes(String(cid))) {
          const b = cell.querySelector(`.conduite-btn[data-type="${dir}"]`);
          if (!b) return;
          if (dir==='A' ? b.classList.contains('btn-primary') : b.classList.contains('btn-success')) n++;
        }
      });
      return n;
    }

    const isActive = btn.classList.contains(type==='A' ? 'btn-primary' : 'btn-success');

    try {
      if (!isActive) {
        // activer -> respecter le plafond
        const used = countActive(type, chantierId, dateIso);
        if (cap > 0 && used >= cap) {
          if (typeof toast === 'function') toast(`Limite atteinte : ${cap} ${type} pour ce chantier.`);
          btn.classList.add('shake'); setTimeout(()=>btn.classList.remove('shake'), 300);
          return;
        }

        await postForm('/pointage/pointage_conduite.php', {
          chantier_id: chantierId, type, utilisateur_id: userId, date_pointage: dateIso
        });

        if (type==='A') { btn.classList.remove('btn-outline-primary'); btn.classList.add('btn-primary'); }
        else           { btn.classList.remove('btn-outline-success'); btn.classList.add('btn-success'); }

        // si cap=1, on libère les autres boutons de ce chantier/jour
        if (cap === 1) {
          document.querySelectorAll(`#pointageApp tbody tr td[data-date="${dateIso}"]`).forEach(otherTd => {
            if (otherTd === td) return;
            const chs = (otherTd.dataset.plannedChantiersDay || '').split(',').filter(Boolean);
            if (!chs.includes(String(chantierId))) return;
            const sel = type==='A' ? '.conduite-btn[data-type="A"]' : '.conduite-btn[data-type="R"]';
            const other = otherTd.querySelector(sel);
            if (!other) return;
            if (type==='A') { other.classList.remove('btn-primary'); other.classList.add('btn-outline-primary'); }
            else            { other.classList.remove('btn-success'); other.classList.add('btn-outline-success'); }
          });
        }

      } else {
        // désactivation
        await postForm('/pointage/pointage_conduite.php', {
          chantier_id: chantierId, type, utilisateur_id: userId, date_pointage: dateIso, remove:'1'
        });
        if (type==='A') { btn.classList.remove('btn-primary'); btn.classList.add('btn-outline-primary'); }
        else           { btn.classList.remove('btn-success'); btn.classList.add('btn-outline-success'); }
      }
    } catch(err){ showError(err.message, err.debug); }
  });

  /* ==============================
   *   PRÉREMPLISSAGE CHEF (1 chantier)
   * ============================== */
  function setChantierOnCell(cell, chantierId) {
    if (!chantierId) return;
    if (cell.dataset.selectedChantierId) return; // ne pas écraser un choix déjà posé
    cell.dataset.selectedChantierId = String(chantierId);

    const nom = (window.CHANTIERS && window.CHANTIERS[chantierId]) || ('Chantier #' + chantierId);
    const holder = cell.querySelector('.chantier-badge');
    if (holder) holder.innerHTML = '<span class="badge rounded-pill bg-secondary">' + nom + '</span>';
  }

  function prefillChefChantierFromPlanning() {
    document.querySelectorAll('#pointageApp tbody tr[data-user-id]').forEach(tr => {
      if ((tr.dataset.role || '').toLowerCase() !== 'chef') return; // seulement les chefs
      tr.querySelectorAll('td[data-date]').forEach(cell => {
        const planned = (cell.dataset.plannedChantiersDay || '')
          .split(',').map(s => s.trim()).filter(Boolean);
        if (planned.length === 1) {
          const cid = asIntOrNull(planned[0]);
          if (cid) setChantierOnCell(cell, cid);
        }
      });
    });
  }

  /* ==============================
   *   INIT
   * ============================== */
  if (activeChantier !== 'all') {
    updateTruckUIFromActiveFilter();
  } else if (camionControls) {
    camionControls.innerHTML = '';
  }

  prefillChefChantierFromPlanning();
  applyFilter();
});

/* ==============================
 *   FILTRE AGENCE (hors IIFE)
 * ============================== */
let CURRENT_AGENCE = "all";

function filterRowsPointage(q) {
  q = (q || "").toLowerCase();
  document.querySelectorAll("table tbody tr[data-user-id]").forEach((tr) => {
    const name = (tr.querySelector(".emp-name")?.innerText || "").toLowerCase();
    const agId = tr.dataset.agenceId || "0";
    const okName   = name.includes(q);
    const okAgence = (CURRENT_AGENCE === "all") ? true : String(agId) === String(CURRENT_AGENCE);
    tr.style.display = (okName && okAgence) ? "" : "none";
  });
}

document.addEventListener("DOMContentLoaded", () => {
  const agBar = document.getElementById("agenceFilters");
  if (agBar) {
    agBar.addEventListener("click", (e) => {
      const btn = e.target.closest("[data-agence]");
      if (!btn) return;
      agBar.querySelectorAll("button").forEach(b=>{
        b.classList.remove("btn-primary"); b.classList.add("btn-outline-secondary");
      });
      btn.classList.remove("btn-outline-secondary"); btn.classList.add("btn-primary");
      CURRENT_AGENCE = btn.dataset.agence || "all";
      const q = document.getElementById("searchInput")?.value || "";
      filterRowsPointage(q);
    });
  }
  const input = document.getElementById("searchInput");
  if (input) {
    let t; input.addEventListener("input", (e) => {
      clearTimeout(t); const v = e.target.value;
      t = setTimeout(() => filterRowsPointage(v), 120);
    });
  }
});
