// /pointage/js/pointage.js

/* =========================================
 *  ÉTAT GLOBAL (source de vérité)
 * ========================================= */
window.POINTAGE_STATE = {
  activeChantier: "all",   // 'all' | chantier_id | '__NONE__' | '__DEPOT__'
  activeAgence: 0,         // 0 = Toutes
  activeDay: null,         // ISO 'YYYY-MM-DD' ou null
  FULL_DAY: 8.25
};
const DEPOT_KEY = '__DEPOT__';

/* Helpers d'accès */
function setActiveChantier(val){ window.POINTAGE_STATE.activeChantier = (val == null ? 'all' : String(val)); }
function getActiveChantier(){ return window.POINTAGE_STATE.activeChantier; }
function setActiveAgence(aid){ window.POINTAGE_STATE.activeAgence = parseInt(aid || '0',10) || 0; }
function getActiveAgence(){ return window.POINTAGE_STATE.activeAgence; }
function setActiveDay(iso){ window.POINTAGE_STATE.activeDay = iso || null; }
function getActiveDay(){ return window.POINTAGE_STATE.activeDay; }

// Récupère le TD pour un jour donné, même si data-date est dans un enfant (.cell-drop / .cell-off)
function getTdForDate(tr, iso){
  // 1) cas simple: l'attribut est sur le TD
  let td = tr.querySelector(`td[data-date="${iso}"]`);
  if (td) return td;
  // 2) fallback: l'attribut est sur un enfant -> on remonte au TD
  const holder = tr.querySelector(`td [data-date="${iso}"]`);
  return holder ? holder.closest('td') : null;
}

// Détection robuste d'une cellule "dépôt" (accepte plusieurs marquages)
function cellIsDepot(td){
  if (!td) return false;
  // attribut(s) sur le TD (avec/ sans accents)
  const raw = (td.dataset.planningType ?? td.dataset.planning_type ?? td.dataset.planning ?? '')
    .normalize('NFD').replace(/\p{Diacritic}/gu,'').toLowerCase();
  if (raw === 'depot') return true;
  if ((td.dataset.isDepot ?? td.dataset.planningDepot ?? td.dataset.depot) === '1') return true;
  // badge interne (si posé dans le contenu)
  if (td.querySelector('[data-type="depot"],[data-planning="depot"],[data-badge="depot"],.assign-chip-depot,.badge-depot,.pill-depot')) return true;
  // texte (dernier recours)
  const txt = (td.textContent || '').normalize('NFD').replace(/\p{Diacritic}/gu,'').toLowerCase();
  if (/\bdepot\b/.test(txt)) return true;
  return false;
}

// ID utilisateur, que la ligne soit data-user-id OU data-emp-id
function getRowUserId(tr){
  const a = tr?.dataset?.userId;
  const b = tr?.dataset?.empId;
  const v = (a ?? b ?? '').trim();
  return v || null;
}

// Sélecteur robuste pour toutes les lignes employés
function selectAllRows(){
  return Array.from(document.querySelectorAll('#pointageApp tbody tr[data-user-id], #pointageApp tbody tr[data-emp-id]'));
}

/* =========================================
 *  UTIL: Résoudre le chantier d'une cellule
 * ========================================= */
function resolveCellChantierId(td){
  const sel = td?.dataset?.selectedChantierId ? parseInt(td.dataset.selectedChantierId,10) : 0;
  if (sel > 0) return sel;

  const btn = document.querySelector('#chantierFilters .active[data-chantier], #chantierFilters .active[data-chantier-id]');
  const fromBtn = btn ? parseInt(btn.dataset.chantier || btn.dataset.chantierId || '0',10) : 0;
  if (fromBtn > 0) return fromBtn;

  let qsId = 0;
  try { qsId = parseInt(new URLSearchParams(location.search).get('chantier_id') || '0', 10) || 0; } catch{}
  if (qsId > 0) return qsId;

  const rootCid = parseInt((document.getElementById('pointageApp')?.dataset.chantierId) || '0', 10);
  if (rootCid > 0) return rootCid;

  const planned = (td?.dataset?.plannedChantiersDay || '').split(',').filter(Boolean);
  if (planned.length === 1) {
    const only = parseInt(planned[0],10);
    if (only > 0) return only;
  }

  const tr = td.closest('tr');
  const rowChs = (tr?.dataset?.chantiers || '').split(',').filter(Boolean);
  if (rowChs.length === 1) {
    const only = parseInt(rowChs[0],10);
    if (only > 0) return only;
  }

  return null; // ambigu
}
window.resolveCellChantierId = resolveCellChantierId;

/* =========================================
 *  Titre dynamique pour non-admins
 * ========================================= */
function majTitreDepuisFiltre(){
  const app = document.getElementById('pointageApp'); if(!app) return;
  if ((app.dataset.role || '').toLowerCase() === 'administrateur') return;

  const titreEl = document.getElementById('pageTitle'); if(!titreEl) return;
  const btnActif = document.querySelector('#chantierFilters button.active[data-chantier], #chantierFilters button.active[data-chantier-id]');
  const nom = btnActif ? (btnActif.textContent || '').trim() : '';
  titreEl.textContent = nom ? ('Pointage ' + nom) : 'Pointage';
}

/* =========================================
 *  PAGE POINTAGE
 * ========================================= */
document.addEventListener('DOMContentLoaded', () => {
  "use strict";

  /* --- Références DOM --- */
  const app            = document.getElementById('pointageApp'); if(!app) return;
  const table          = app.querySelector('table');
  const thead          = table?.querySelector('thead');
  const searchInput    = document.getElementById('searchInput');
  const filtersBar     = document.getElementById('chantierFilters');
  const camionControls = document.getElementById('camionControls');

  // Absence (modale)
  const absenceModalEl = document.getElementById('absenceModal');
  const absenceModal   = absenceModalEl ? new bootstrap.Modal(absenceModalEl) : null;
  const absForm        = document.getElementById('absenceForm');
  const absSaveBtn     = document.getElementById('absenceSave');

  /* --- Helpers --- */
  const DEBUG = false;
  const showError = (msg, ctx) => { alert(msg || 'Erreur serveur'); if (DEBUG && ctx) console.error('[POINTAGE]', msg, ctx); };

  function todayLocalISO(){
    const d = new Date();
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0,10);
  }
  const todayIso = todayLocalISO();

  const asIntOrNull = (v)=>{ const n = parseInt(v,10); return Number.isFinite(n) && n>0 ? n : null; };

  async function postForm(url, payload){
    const body = new URLSearchParams();
    Object.entries(payload || {}).forEach(([k,v])=> body.append(k, v==null ? '' : String(v)));
    const res  = await fetch(url,{ method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body, credentials:'same-origin' });
    const text = await res.text();
    let data = null;
    try{ data = JSON.parse(text); }catch{ data = null; }
    if (data === 'ok') data = { success:true };
    if (!res.ok || !data || data.success === false) {
      const msg = (data && (data.message || data.msg)) || `HTTP ${res?.status || 0}`;
      const err = new Error(msg);
      err.debug = { url, payload, status: res?.status, responseText: text, data };
      throw err;
    }
    return data;
  }

  function formatHM(dec){ const h=Math.floor(dec); const m=Math.round((dec-h)*60); return `${h}h${String(m).padStart(2,'0')}`; }
  const FULL_DAY = window.POINTAGE_STATE.FULL_DAY;
  const LABEL_REASON = {
    conges_payes:'Congés payés', conges_intemperies:'Congés intempéries',
    maladie:'Maladie', justifie:'Justifié', injustifie:'Injustifié'
  };
  const labelForReason = r => LABEL_REASON[r] || r;
  const formatHours = h => Number(h).toLocaleString('fr-FR',{ maximumFractionDigits:2, useGrouping:false });

  /* --- Conduite: badges trajet --- */
  function renderTrajet({ km, min, depotName }){
    const kmEl = document.getElementById('trajetKm');
    const minEl = document.getElementById('trajetMin');
    const zoneEl = document.getElementById('trajetZone');
    const warnEl = document.getElementById('trajetWarn');
    const depotPhraseEl = document.getElementById('trajetDepotPhrase');

    if (warnEl) warnEl.classList.add('d-none');

    if (typeof km === 'number' && kmEl){ kmEl.textContent = km.toLocaleString('fr-FR',{ minimumFractionDigits:1, maximumFractionDigits:1 }) + ' km'; kmEl.classList.remove('d-none'); }
    else kmEl?.classList.add('d-none');

    if (typeof min === 'number' && minEl){ minEl.textContent = `${min} min`; minEl.classList.remove('d-none'); }
    else minEl?.classList.add('d-none');

    if (zoneEl){
      if (typeof min === 'number'){
        let z = { label:'Z1', cls:'badge text-bg-success' };
        if (min > 10 && min <= 20) z = { label:'Z2', cls:'badge text-bg-primary' };
        else if (min > 20 && min <= 30) z = { label:'Z3', cls:'badge text-bg-warning text-dark' };
        else if (min > 30) z = { label:'Z4', cls:'badge text-bg-danger' };
        zoneEl.className = z.cls;
        zoneEl.textContent = z.label;
        zoneEl.classList.remove('d-none');
      } else zoneEl.classList.add('d-none');
    }

    if (depotPhraseEl){
      if (depotName && depotName.trim() !== ''){ depotPhraseEl.textContent = `du dépôt de ${depotName}`; depotPhraseEl.classList.remove('d-none'); }
      else depotPhraseEl.classList.add('d-none');
    }
  }
  window.renderTrajet = renderTrajet;

  async function refreshConduiteLocked(force=false){
    const wrap = document.getElementById('conduiteWrap'); if(!wrap) return;

    // Si le filtre actif est le DEPOT => pas de calcul de trajet
    const active = getActiveChantier();
    if (active === DEPOT_KEY){ 
      renderTrajet({ km: undefined, min: undefined, depotName: '' });
      return;
    }

    const chantierId = parseInt(wrap.dataset.chantier || wrap.dataset.chantierId || '0', 10) || 0;
    if (!chantierId) { renderTrajet({ km: undefined, min: undefined, depotName: '' }); return; }

    try{
      wrap.classList.add('opacity-50');
      const fd = new FormData();
      fd.set('action', 'compute');
      fd.set('chantier_id', String(chantierId));
      if (wrap.dataset.depotId) fd.set('depot_id', String(parseInt(wrap.dataset.depotId,10)));
      if (force) fd.set('force', '1');

      const res = await fetch('/chantiers/services/trajet_api.php',{ method:'POST', body:fd, credentials:'same-origin' });
      const ct = res.headers.get('content-type') || '';
      const txt = await res.text();
      if (!ct.includes('application/json')) throw new Error(txt.slice(0,200));
      const json = JSON.parse(txt);
      if (!res.ok || !json.ok) throw new Error(json.error || 'Erreur trajet');

      const dureeS = (json.duration_s ?? json.duree_s);
      if (json.distance_m != null && dureeS != null){
        const km = Math.round(json.distance_m/100)/10;
        const min = Math.round(dureeS/60);
        const depotName = (wrap.dataset.depotName || '').trim();
        renderTrajet({ km, min, depotName });
      }
    }catch(e){ console.error('[trajet]', e); }
    finally{ wrap.classList.remove('opacity-50'); }
  }
  window.refreshConduiteLocked = refreshConduiteLocked;

  /* --- Entête / jour actif --- */
  const headerThs = Array.from(thead?.querySelectorAll('tr th[data-iso]') || []);
  const dayIsos = headerThs.map(th => th.dataset.iso);
  setActiveDay(dayIsos.includes(todayIso) ? todayIso : null);

  const getActiveColIndex = ()=>{ const ad=getActiveDay(); return ad ? (dayIsos.indexOf(ad)+1) : -1; };

  function highlightActiveDay(){
    document.querySelectorAll('.day-active').forEach(el=> el.classList.remove('day-active'));
    const col = getActiveColIndex(); if (col < 0) return;
    const th = thead?.querySelectorAll('tr th')[col]; if (th) th.classList.add('day-active');
    document.querySelectorAll('#pointageApp tbody tr').forEach(tr=>{
      const td = tr.querySelectorAll('td')[col];
      if (td) td.classList.add('day-active');
    });
  }

  /**
   * Sélectionne un "jour actif" compatible avec le filtre (chantier ou dépôt).
   */
  function ensureActiveDayForChantier(cid) {
    const wanted = String(cid);
    const dayIsos = Array.from(document.querySelectorAll('thead tr th[data-iso]')).map(th => th.dataset.iso);

    const activeAgence = getActiveAgence(); // 0 = toutes
    const rowMatchesAgence = (tr) => {
      if (activeAgence === 0) return true;
      const rowAid = parseInt(tr.dataset.agenceId || '0', 10) || 0;
      return rowAid === activeAgence;
    };

    const hasAnyOnDay = (iso) => {
      // on ne considère que les lignes de l’agence active
      const rows = selectAllRows().filter(rowMatchesAgence);

      if (wanted === DEPOT_KEY) {
        // au moins une cellule marquée "dépôt" sur ce jour
        return rows.some(tr => {
          const td = getTdForDate(tr, iso);
          return cellIsDepot(td);
        });
      }

      // chantier normal : pastille chantier dans la cellule du jour
      return rows.some(tr => {
        const td = getTdForDate(tr, iso);
        if (!td) return false;
        const planned = (td.dataset.plannedChantiersDay || '').split(',').filter(Boolean);
        return planned.includes(wanted);
      });
    };

    const current = getActiveDay();
    if (!current || !hasAnyOnDay(current)) {
      for (const iso of dayIsos) {
        if (hasAnyOnDay(iso)) {
          setActiveDay(iso);
          highlightActiveDay();
          return true;
        }
      }
    }
    return false;
  }

  thead?.addEventListener('click', (e)=>{
    const th = e.target.closest('th[data-iso]'); if(!th) return;
    setActiveDay(th.dataset.iso || null);
    if (getActiveChantier() !== 'all') updateTruckUIFromActiveFilter();
    else if (camionControls) camionControls.innerHTML = '';
    applyFilter();
  });

  /* --- Init état chantier actif depuis DOM --- */
  (function initActiveChantierFromDOM(){
    const btn = filtersBar?.querySelector('button.active[data-chantier],button.active[data-chantier-id]');
    const role = (app.dataset.role || '').toLowerCase();
    if (role !== 'administrateur' && btn?.textContent){
      const title = document.getElementById('pageTitle'); if (title) title.textContent = 'Pointage ' + btn.textContent.trim();
    }
    const val = btn?.dataset.chantier || btn?.dataset.chantierId || 'all';
    setActiveChantier(val);
  })();
  majTitreDepuisFiltre();

  /* --- Filtrage lignes --- */
  let query = '';

  function rowMatches(tr){
    const activeCh = getActiveChantier();

    if (activeCh === '__NONE__') return false;

    if (query){
      const name = (tr.dataset.name || '').toLowerCase();
      if (!name.includes(query)) return false;
    }

    // Mode agence (aucun chantier précis)
    if (activeCh === 'all'){
      const ag = getActiveAgence();
      if (ag !== 0){
        const rowAid = parseInt(tr.dataset.agenceId || '0', 10) || 0;
        if (rowAid !== ag) return false;
      }
      return true;
    }

    // Mode chantier précis → on filtre sur le JOUR ACTIF uniquement
    const colIso = getActiveDay() || null;
    if (!colIso) return false;

    const td = getTdForDate(tr, colIso);
    if (!td) return false;

    if (activeCh === DEPOT_KEY) {
      // agence d'abord
      const ag = getActiveAgence();
      const rowAid = parseInt(tr.dataset.agenceId || '0', 10) || 0;
      if (ag !== 0 && rowAid !== ag) return false;

      // cellule marquée "dépôt" (tolérant)
      return cellIsDepot(td);
    }

    const planned = (td.dataset.plannedChantiersDay || '').split(',').map(s=>s.trim()).filter(Boolean);
    return planned.includes(String(activeCh));
  }

  function applyFilter(){
    highlightActiveDay();
    selectAllRows().forEach(tr=>{
      tr.classList.toggle('d-none', !rowMatches(tr));
    });
  }
  window.applyPointageFilter = applyFilter;

  searchInput?.addEventListener('input', ()=>{
    query = (searchInput.value || '').toLowerCase();
    applyFilter();
  });

  /* --- Barre chantiers : clic --- */
  filtersBar?.addEventListener('click', (e)=>{
    const btn = e.target.closest('button[data-chantier],button[data-chantier-id]');
    if (!btn) return;

    // style visuel
    filtersBar.querySelectorAll('button').forEach(b=>{
      b.classList.remove('active','btn-primary');
      b.classList.add('btn-outline-primary');
    });
    btn.classList.remove('btn-outline-primary');
    btn.classList.add('active','btn-primary');

    const val = btn.dataset.chantier ?? btn.dataset.chantierId ?? 'all';
    setActiveChantier(val);

    // Si un chantier précis (incluant DEPOT) est choisi → on s'aligne automatiquement sur un jour qui a une pastille
    if (getActiveChantier() !== 'all' && getActiveChantier() !== '__NONE__'){
      ensureActiveDayForChantier(getActiveChantier());
    }

    // Verrouille la conduite sur le chantier choisi (si réel id numérique)
    const wrap = document.getElementById('conduiteWrap');
    if (wrap){
      const num = parseInt(getActiveChantier(),10) || 0;
      wrap.dataset.chantier = String(num); // DEPOT => 0 (pas de calcul trajet)
    }
    refreshConduiteLocked(true);

    // maj URL (chantier_id uniquement si numérique)
    try{
      const url = new URL(window.location.href);
      const cid = parseInt(getActiveChantier(), 10);
      if (!cid){ url.searchParams.delete('chantier_id'); }
      else { url.searchParams.set('chantier_id', String(cid)); }
      history.replaceState(null,'',url);
    }catch{}

    if (getActiveChantier() !== 'all') updateTruckUIFromActiveFilter();
    else {
      camionControls && (camionControls.innerHTML = '');
      renderTrajet({ km: undefined, min: undefined, depotName: '' });
    }
    majTitreDepuisFiltre();
    applyFilter();
    refreshConduiteLocked();
  });

  /* --- Sélecteur camions --- */
  const DAYS = Array.isArray(window.POINTAGE_DAYS) ? window.POINTAGE_DAYS : [];
  if (typeof window.truckCap === 'undefined') window.truckCap = new Map();
  const truckCap = window.truckCap;
  const capKey   = (c,d)=>`${c}|${d}`;
  const getCap   = (c,d)=> (truckCap.get(capKey(c,d)) ?? 1);

  async function fetchCfg(chantierId){
    const r = await fetch(`${window.API_CAMIONS_CFG}?chantier_id=${encodeURIComponent(chantierId)}`, { credentials:'same-origin' });
    const j = await r.json(); if(!j.ok) throw new Error(j.message||'GET cfg');
    return Number(j.nb)||1;
  }
  async function saveCfg(chantierId, nb){
    const body = new URLSearchParams({chantier_id:String(chantierId), nb:String(nb)});
    const r = await fetch(window.API_CAMIONS_CFG,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},credentials:'same-origin',body});
    const j = await r.json(); if(!j.ok) throw new Error(j.message||'POST cfg');
    return Number(j.nb)||1;
  }
  function applyCfgToWeek(chantierId, nb){ DAYS.forEach(d=> truckCap.set(capKey(chantierId,d), Math.max(1, Number(nb)||1))); }

  function renderTruckStepper(chantierId, nbInit){
    const host = document.getElementById('camionControls'); if(!host) return;
    host.innerHTML = '';
    if(!chantierId || chantierId==='all') return;
    host.insertAdjacentHTML('afterbegin', `
      <label for="camionCount" class="mb-0 small text-muted me-2">Nombre de camions</label>
      <div class="input-group input-group-sm camion-stepper" style="width:140px">
        <button class="btn btn-outline-secondary" type="button" data-action="decr" aria-label="Diminuer">−</button>
        <input id="camionCount" type="text" class="form-control text-center" value="${nbInit}" inputmode="numeric" pattern="[0-9]*" aria-label="Nombre de camions">
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
      commit(cur + (btn.dataset.action==='incr' ? 1 : -1));
    });
    input.addEventListener('input', ()=>{ input.value = input.value.replace(/\d+/g, match => match).replace(/\D+/g,''); });
    input.addEventListener('change', ()=> commit(input.value));
    input.addEventListener('keydown', (e)=>{ if(e.key==='Enter'){ e.preventDefault(); input.blur(); } });
    commit(nbInit, false);
  }

  async function updateTruckUIFromActiveFilter(){
    const bar = document.getElementById('chantierFilters'); if(!bar) return;
    const btn = bar.querySelector('button.active[data-chantier]');
    const cidRaw = btn ? btn.dataset.chantier : null;

    // Si "Dépôt" est actif ou pas d’id num → vider l’UI
    const cid = parseInt(cidRaw || '',10);
    const host= document.getElementById('camionControls');
    if(!cid || cidRaw === DEPOT_KEY){ if(host) host.innerHTML=''; return; }

    try { const nb = await fetchCfg(cid); renderTruckStepper(cid, nb); }
    catch(e){ console.error(e); renderTruckStepper(cid, 1); }
  }
  window.updateTruckUIFromActiveFilter = updateTruckUIFromActiveFilter;

  (function initCamionUI(){
    const b = document.querySelector('#chantierFilters .active[data-chantier]');
    if (b) updateTruckUIFromActiveFilter();
  })();

  /* --- Présence ON/OFF --- */
  table?.addEventListener('click', async (e)=>{
    const btn = e.target.closest('.present-btn'); if(!btn) return;
    const td = btn.closest('td'); const tr = btn.closest('tr');
    const userId = getRowUserId(tr);
    const dateIso = td?.dataset.date || td.querySelector('[data-date]')?.dataset?.date;
    if (!userId || !dateIso) return;

    const hours = btn.dataset.hours || '8.25';
    const isActive = btn.classList.contains('btn-success');
    const chantierId = resolveCellChantierId(td);

    try{
      if (isActive){
        const payload = { utilisateur_id:userId, date:dateIso, hours:'0' };
        if (chantierId) payload.chantier_id = chantierId;
        await postForm('/pointage/pointage_present.php', payload);
        btn.classList.remove('btn-success'); btn.classList.add('btn-outline-success'); btn.textContent = 'Présent 8h15';
        return;
      }

      const del = { utilisateur_id:userId, date:dateIso, remove:'1' };
      if (chantierId) del.chantier_id = chantierId;
      try{ await postForm('/pointage/pointage_absence.php', del); }catch{}

      const absBtn = td.querySelector('.absence-btn');
      if (absBtn){
        absBtn.classList.remove('btn-danger'); absBtn.classList.add('btn-outline-danger');
        absBtn.textContent = 'Abs.'; absBtn.dataset.hasAbsence = '0';
        absBtn.removeAttribute('data-reason'); absBtn.removeAttribute('data-hours');
      }
      td.querySelector('.absence-pill')?.remove();
      td.querySelectorAll('.conduite-btn').forEach(b=>{ b.disabled=false; b.classList.remove('d-none'); });

      const payload = { utilisateur_id:userId, date:dateIso, hours };
      if (chantierId) payload.chantier_id = chantierId;
      await postForm('/pointage/pointage_present.php', payload);

      btn.classList.remove('btn-outline-success'); btn.classList.add('btn-success'); btn.textContent = 'Présent 8h15';
    }catch(err){ showError(err.message, err.debug); }
  });

  /* --- Absence : ouverture modale --- */
  table?.addEventListener('click', (e)=>{
    const trigger = e.target.closest('.absence-btn,[data-click-absence]'); if(!trigger) return;
    const td = trigger.closest('td'); const tr = trigger.closest('tr'); if(!td || !tr) return;
    if (absenceModalEl) absenceModalEl._targetCell = td;

    document.getElementById('absUserId').value = getRowUserId(tr) || '';
    document.getElementById('absDate').value   = td.dataset.date || td.querySelector('[data-date]')?.dataset?.date || '';

    let reason = (trigger.getAttribute('data-reason') || '').toLowerCase();
    let hours  = parseFloat(trigger.getAttribute('data-hours') || '0');

    if (!reason){
      const plan = (td.dataset.planningType || '').toLowerCase();
      if (plan === 'maladie') reason = 'maladie';
      else if (plan === 'conges') reason = 'conges_payes';
      else reason = 'injustifie';
    }
    if (!hours || Number.isNaN(hours)) hours = 8.25;

    document.querySelectorAll('#absenceForm input[name="reason"]').forEach(r=> r.checked = (r.value.toLowerCase() === reason));
    const fHours = document.getElementById('absHours'); if (fHours) fHours.value = hours.toFixed(2);

    const delBtn = document.getElementById('absenceDelete');
    const hasAbs = (trigger.getAttribute('data-has-absence') === '1') || trigger.classList.contains('btn-danger');
    if (delBtn) delBtn.classList.toggle('d-none', !hasAbs);

    absenceModal?.show();
  });

  // Enregistrer absence
  absSaveBtn?.addEventListener('click', async ()=>{
    if (!absenceModalEl?._targetCell) return;
    const td      = absenceModalEl._targetCell;
    const userId  = document.getElementById('absUserId')?.value || '';
    const dateIso = document.getElementById('absDate')?.value  || '';
    const hours   = parseFloat(document.getElementById('absHours')?.value || '0');
    const reason  = (absForm?.querySelector('input[name="reason"]:checked')?.value || 'injustifie');
    if (!userId || !dateIso) return;
    if (!hours || hours < 0.25 || hours > 8.25){ showError('Saisis un nombre d’heures valide (0.25 à 8.25).'); return; }

    const chantierId = resolveCellChantierId(td);
    try{
      const payloadAbs = { utilisateur_id:userId, date:dateIso, reason, hours:String(hours) };
      if (chantierId) payloadAbs.chantier_id = chantierId;
      await postForm('/pointage/pointage_absence.php', payloadAbs);

      const absBtn = td.querySelector('.absence-btn');
      if (absBtn){
        absBtn.classList.remove('btn-outline-danger'); absBtn.classList.add('btn-danger');
        absBtn.textContent = `${labelForReason(reason)} ${formatHours(hours)} h`;
        absBtn.dataset.reason = reason; absBtn.dataset.hours = hours.toFixed(2); absBtn.dataset.hasAbsence = '1';
      }
      td.querySelector('.absence-pill')?.remove();

      const remaining = Math.max(0, FULL_DAY - hours);
      const presentBtn = td.querySelector('.present-btn');

      const payloadPres = { utilisateur_id:userId, date:dateIso, hours:String(remaining) };
      if (chantierId) payloadPres.chantier_id = chantierId;
      await postForm('/pointage/pointage_present.php', payloadPres);

      if (presentBtn){
        if (remaining > 0){
          presentBtn.classList.remove('btn-outline-success'); presentBtn.classList.add('btn-success');
          presentBtn.dataset.hours = String(remaining); presentBtn.textContent = 'Présent ' + formatHM(remaining);
        } else {
          presentBtn.classList.remove('btn-success'); presentBtn.classList.add('btn-outline-success');
          presentBtn.dataset.hours = String(FULL_DAY); presentBtn.textContent = 'Présent 8h15';
        }
      }

      const isIntemp = (reason === 'conges_intemperies');
      const btnA = td.querySelector('.conduite-btn[data-type="A"]');
      const btnR = td.querySelector('.conduite-btn[data-type="R"]');
      if (btnA) { btnA.disabled = !isIntemp; btnA.classList.toggle('d-none', !isIntemp); }
      if (btnR) { btnR.disabled = true; btnR.classList.add('d-none'); }

      absenceModal?.hide();
    }catch(err){ showError(err.message, err.debug); }
  });

  // Supprimer absence
  document.getElementById('absenceDelete')?.addEventListener('click', async ()=>{
    const uid = document.getElementById('absUserId').value;
    const date = document.getElementById('absDate').value;
    const td = (document.getElementById('absenceModal')._targetCell) || null;
    try{
      const resp = await fetch('/pointage/pointage_absence.php',{
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, credentials:'same-origin',
        body: new URLSearchParams({ utilisateur_id: uid, date, remove: '1' })
      });
      const data = await resp.json();
      if (!resp.ok || !data.success) throw new Error(data.message || 'Erreur');

      if (td){
        const absBtn = td.querySelector('.absence-btn');
        if (absBtn){
          absBtn.classList.remove('btn-danger'); absBtn.classList.add('btn-outline-danger');
          absBtn.textContent = 'Abs.'; absBtn.removeAttribute('data-reason'); absBtn.removeAttribute('data-hours');
          absBtn.dataset.hasAbsence = '0';
        }
        td.querySelectorAll('.conduite-btn').forEach(b=>{ b.disabled=false; b.classList.remove('d-none'); });
      }
      bootstrap.Modal.getInstance(document.getElementById('absenceModal'))?.hide();
    }catch(err){ alert(err.message || 'Erreur réseau'); }
  });

  /* --- Conduite A/R --- */
  table?.addEventListener('click', async (e)=>{
    const btn = e.target.closest('.conduite-btn'); if(!btn || btn.disabled) return;
    const td = btn.closest('td'); const tr = btn.closest('tr');
    const userId = getRowUserId(tr);
    const dateIso = td?.dataset.date || td.querySelector('[data-date]')?.dataset?.date;
    const type = btn?.dataset.type;
    if (!userId || !dateIso || !type) return;

    const chantierId = resolveCellChantierId(td);
    if (!chantierId){ alert("Ambiguïté chantier : sélectionne un chantier (bouton de filtre) ou définis-le sur la cellule."); return; }

    const capK = (c,d)=>`${c}|${d}`;
    const getCapLocal = (c,d)=> ((window.truckCap || new Map()).get(capK(c,d)) ?? 1);
    const cap = getCapLocal(chantierId, dateIso);

    function countActive(dir, cid, dIso){
      let n = 0;
      // attrape td portant data-date OU un descendant qui le porte
      document.querySelectorAll(`#pointageApp tbody td[data-date="${dIso}"], #pointageApp tbody td :is([data-date="${dIso}"])`).forEach(node=>{
        const td = node.closest('td') || node;
        const chs = (td.dataset.plannedChantiersDay || '').split(',').filter(Boolean);
        if (!chs.includes(String(cid))) return;
        const b = td.querySelector(`.conduite-btn[data-type="${dir}"]`);
        if (!b) return;
        if (dir === 'A' ? b.classList.contains('btn-primary') : b.classList.contains('btn-success')) n++;
      });
      return n;
    }

    const isActive = btn.classList.contains(type==='A' ? 'btn-primary' : 'btn-success');

    try{
      if (!isActive){
        const used = countActive(type, chantierId, dateIso);
        if (cap > 0 && used >= cap){ btn.classList.add('shake'); setTimeout(()=>btn.classList.remove('shake'),300); return; }

        await postForm('/pointage/pointage_conduite.php', { chantier_id:chantierId, type, utilisateur_id:userId, date_pointage:dateIso });

        if (type==='A'){ btn.classList.remove('btn-outline-primary'); btn.classList.add('btn-primary'); }
        else           { btn.classList.remove('btn-outline-success'); btn.classList.add('btn-success'); }

        if (cap === 1){
          // désactiver les autres pour ce jour/chantier
          document.querySelectorAll(`#pointageApp tbody td[data-date="${dateIso}"], #pointageApp tbody td :is([data-date="${dateIso}"])`).forEach(node=>{
            const otherTd = node.closest('td') || node;
            if (otherTd === td) return;
            const chs = (otherTd.dataset.plannedChantiersDay || '').split(',').filter(Boolean);
            if (!chs.includes(String(chantierId))) return;
            const sel = type==='A' ? '.conduite-btn[data-type="A"]' : '.conduite-btn[data-type="R"]';
            const other = otherTd.querySelector(sel); if (!other) return;
            if (type==='A'){ other.classList.remove('btn-primary'); other.classList.add('btn-outline-primary'); }
            else           { other.classList.remove('btn-success'); other.classList.add('btn-outline-success'); }
          });
        }
      } else {
        await postForm('/pointage/pointage_conduite.php', { chantier_id:chantierId, type, utilisateur_id:userId, date_pointage:dateIso, remove:'1' });
        if (type==='A'){ btn.classList.remove('btn-primary'); btn.classList.add('btn-outline-primary'); }
        else           { btn.classList.remove('btn-success'); btn.classList.add('btn-outline-success'); }
      }
    }catch(err){ alert(err.message || 'Erreur réseau'); }
  });

  /* --- Pré-remplissage chantier pour CHEF (si 1 planifié) --- */
  function setChantierOnCell(cell, chantierId){
    if (!chantierId) return;
    if (cell.dataset.selectedChantierId) return;
    cell.dataset.selectedChantierId = String(chantierId);
    const nom = (window.CHANTIERS && window.CHANTIERS[chantierId]) || ('Chantier #' + chantierId);
    const holder = cell.querySelector('.chantier-badge');
    if (holder) holder.innerHTML = '<span class="badge rounded-pill bg-secondary">' + nom + '</span>';
  }
  function prefillChefChantierFromPlanning(){
    selectAllRows().forEach(tr=>{
      if ((tr.dataset.role || '').toLowerCase() !== 'chef') return;
      tr.querySelectorAll('td[data-date]').forEach(cell=>{
        const planned = (cell.dataset.plannedChantiersDay || '').split(',').map(s=>s.trim()).filter(Boolean);
        if (planned.length === 1){ const cid = asIntOrNull(planned[0]); if (cid) setChantierOnCell(cell, cid); }
      });
    });
  }

  /* --- INIT --- */
  if (getActiveChantier() !== 'all') updateTruckUIFromActiveFilter();
  else camionControls && (camionControls.innerHTML = '');

  prefillChefChantierFromPlanning();
  applyFilter();
  refreshConduiteLocked(false);
});

/* ==============================================================
 *  Agence + barre des chantiers (sous le titre)
 * ==============================================================
 */
(function(){
  const agBar = document.getElementById('agenceFilters');
  const chBar = document.getElementById('chantierFilters');
  if (!agBar || !chBar) return;

  function setActive(btn, active){
    btn.classList.toggle('active', active);
    btn.classList.toggle('btn-primary', active);
    btn.classList.toggle('btn-outline-primary', !active);
  }

  function renderChantiers(agenceId, selectedCid=0){
    const host = document.getElementById('chantierFilters'); if(!host) return;
    host.className = 'd-flex flex-wrap justify-content-center gap-2 my-2';
    host.innerHTML = '';

    // Ajout du bouton "Dépôt"
    const depotBtn = document.createElement('button');
    depotBtn.type = 'button';
    depotBtn.className = 'btn btn-outline-primary';
    depotBtn.dataset.chantier = DEPOT_KEY;
    depotBtn.textContent = 'Dépôt';
    setActive(depotBtn, String(selectedCid) === DEPOT_KEY); // robuste
    host.appendChild(depotBtn);

    const list = (Array.isArray(window.CHANTIERS_LIST) ? window.CHANTIERS_LIST : [])
      .filter(c => agenceId === 0 ? true : Number(c.agence_id || 0) === Number(agenceId))
      .sort((a,b)=> (a.nom||'').localeCompare(b.nom||'', 'fr'));

    for (const c of list){
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn-outline-primary';
      btn.dataset.chantier = String(c.id);
      btn.textContent = c.nom;
      setActive(btn, String(selectedCid) === String(c.id));
      host.appendChild(btn);
    }
  }
  window.renderChantiersForAgence = renderChantiers;

  // Clic agence
  agBar.addEventListener('click', (e)=>{
    const btn = e.target.closest('[data-agence]'); if(!btn) return;

    agBar.querySelectorAll('[data-agence]').forEach(b=>{ b.classList.remove('active','btn-primary'); b.classList.add('btn-outline-primary'); });
    btn.classList.remove('btn-outline-primary'); btn.classList.add('active','btn-primary');

    const agenceId = parseInt(btn.dataset.agence || '0',10) || 0;
    setActiveAgence(agenceId);

    renderChantiers(agenceId, 0);

    const chBarNow = document.getElementById('chantierFilters');
    // IMPORTANT: on ignore le bouton DEPOT dans le test “a-t-on des chantiers ?”
    const hasChantiers = !!chBarNow.querySelector('button[data-chantier]:not([data-chantier="__DEPOT__"]),button[data-chantier-id]');
    setActiveChantier(hasChantiers ? 'all' : '__NONE__');

    chBarNow.querySelectorAll('button').forEach(b=>{ b.classList.remove('active','btn-primary'); b.classList.add('btn-outline-primary'); });

    document.getElementById('camionControls')?.replaceChildren();
    if (typeof window.renderTrajet === 'function') window.renderTrajet({ km: undefined, min: undefined, depotName: '' });
    try{
      const url = new URL(window.location.href);
      url.searchParams.delete('chantier_id');
      history.replaceState(null,'',url);
    }catch{}

    window.applyPointageFilter?.();
    majTitreDepuisFiltre();
  });

  // Init (rendu initial)
  (function init(){
    agBar.querySelectorAll('[data-agence]').forEach(b=>{ b.classList.remove('active','btn-primary'); b.classList.add('btn-outline-primary'); });
    const bTous = agBar.querySelector('[data-agence="0"]');
    if (bTous){ bTous.classList.add('active','btn-primary'); bTous.classList.remove('btn-outline-primary'); }

    const url = new URL(window.location.href);
    const qsChantierId = parseInt(url.searchParams.get('chantier_id') || '0',10) || 0;

    renderChantiers(0, qsChantierId || 0);

    const appRole = (document.getElementById('pointageApp')?.dataset.role || '').toLowerCase();
    if (appRole === 'administrateur'){
      document.querySelectorAll('#chantierFilters button').forEach(b=>{ b.classList.remove('active','btn-primary'); b.classList.add('btn-outline-primary'); });
    }
  })();
})();

/* ==============================================================
 *  Tâche du jour (modale)
 * ==============================================================
 */
(function(){
  const root = document.getElementById('pointageApp') || document.body; if(!root) return;

  const csrf    = root.dataset.csrfToken || document.querySelector('input[name="csrf_token"]')?.value || '';
  const modalEl = document.getElementById('tacheJourModal'); if(!modalEl) return;

  const modal      = new bootstrap.Modal(modalEl);
  const listTache  = document.getElementById('tj_list');
  const hidTacheId = document.getElementById('tj_tache_id');
  const hidUid     = document.getElementById('tj_utilisateur_id');
  const hidDate    = document.getElementById('tj_date_jour');
  const form       = document.getElementById('tacheJourForm');
  const searchTache= document.getElementById('searchTache') || modalEl.querySelector('#searchTache');

  function renderTacheList(taches, selectedId=''){
    listTache.innerHTML = '';
    taches.forEach(t=>{
      const btn = document.createElement('button');
      btn.type='button';
      btn.className='list-group-item list-group-item-action d-flex justify-content-between align-items-center';
      btn.dataset.id = String(t.id).trim();
      btn.textContent = t.libelle;

      const isSelected = String(selectedId) === String(t.id);
      if (isSelected) btn.classList.add('active');

      const ok = document.createElement('span');
      ok.dataset.check='1'; ok.className='ms-2 small'; ok.textContent='✓';
      ok.style.visibility = isSelected ? 'visible' : 'hidden';
      btn.appendChild(ok);

      btn.addEventListener('click', ()=>{
        const clickedId = String(t.id).trim();
        const already = String(hidTacheId.value || '') === clickedId;
        hidTacheId.value = already ? '' : clickedId;

        listTache.querySelectorAll('.list-group-item').forEach(el=>{
          el.classList.remove('active');
          const mark = el.querySelector('[data-check]'); if (mark) mark.style.visibility = 'hidden';
        });
        if (!already){ btn.classList.add('active'); ok.style.visibility='visible'; }

        modal.hide(); setTimeout(()=> form.requestSubmit(), 0);
      });

      listTache.appendChild(btn);
    });
  }

  let TACHES_CACHE = [];

  root.addEventListener('click', async (e)=>{
    const cell = e.target.closest('[data-click-tache]'); if(!cell) return;

    const td = cell.closest('td'); modalEl._td = td;

    const utilisateurId = parseInt(cell.dataset.userId || getRowUserId(cell.closest('tr')) || '0', 10);
    const dateJour      = cell.dataset.date || '';
    if (!utilisateurId || !dateJour) return;

    const preferCid = parseInt(cell.dataset.ptChantierId || '0',10);
    const cid = preferCid || resolveCellChantierId(td);
    if (!cid){ alert("Sélectionne un chantier (bouton de filtre) ou affecte clairement la cellule."); return; }

    modalEl.dataset.cid = String(cid);
    hidUid.value  = utilisateurId;
    hidDate.value = dateJour;
    hidTacheId.value = cell.dataset.tacheId || '';

    try{ modal.show(); }catch{}

    try{
      const res = await fetch('/pointage/api/taches_list.php',{
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ csrf_token: csrf, chantier_id: cid })
      });
      const j = await res.json();
      if (!j?.success) throw new Error(j?.message || 'Erreur de chargement des tâches');
      TACHES_CACHE = j.taches || [];
      renderTacheList(TACHES_CACHE, hidTacheId.value);
    }catch(err){
      listTache.innerHTML = '<div class="text-muted p-2">(Liste indisponible)</div>';
      console.error(err);
    }
  });

  if (searchTache){
    searchTache.addEventListener('input', ()=>{
      const q = (searchTache.value || '').toLowerCase();
      const filtered = TACHES_CACHE.filter(t => (t.libelle || '').toLowerCase().includes(q));
      renderTacheList(filtered, hidTacheId.value);
    });
  }

  function getCellHours(td){
    const presentBtn = td.querySelector('.present-btn');
    if (presentBtn && presentBtn.classList.contains('btn-success')){
      const h = parseFloat(presentBtn.dataset.hours || '0'); if (h>0) return h;
    }
    const absBtn = td.querySelector('.absence-btn.btn-danger');
    if (absBtn){
      const m = absBtn.textContent.match(/(\d+[.,]?\d*)/);
      if (m){ const abs = parseFloat(m[1].replace(',','.')) || 0; return Math.max(0, window.POINTAGE_STATE.FULL_DAY - abs); }
    }
    return window.POINTAGE_STATE.FULL_DAY;
  }

  form?.addEventListener('submit', async (e)=>{
    e.preventDefault();

    let cid = parseInt(modalEl.dataset.cid || '0', 10);
    if (!cid){
      const cell = modalEl._td?.querySelector('[data-click-tache]') || modalEl._td;
      cid = parseInt(cell?.dataset?.ptChantierId || '0',10) || resolveCellChantierId(modalEl._td) || 0;
      if (cid) modalEl.dataset.cid = String(cid);
    }

    const payload = {
      csrf_token: csrf,
      chantier_id: cid,
      utilisateur_id: parseInt(hidUid.value || '0',10),
      date_jour: hidDate.value || '',
      tache_id: Number(String(hidTacheId.value || '0').trim()),
      heures: 0
    };
    if (!payload.utilisateur_id || !payload.date_jour || !payload.chantier_id) return;
    payload.heures = getCellHours(modalEl._td);

    try{
      const res = await fetch('/pointage/api/save_tache.php', {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)
      });
      const j = await res.json();

      if (!j?.success && payload.tache_id === 0){
        const selector = `[data-click-tache][data-user-id="${payload.utilisateur_id}"][data-date="${payload.date_jour}"]`;
        const cell = document.querySelector(selector);
        if (cell){ cell.dataset.tacheId=''; cell.removeAttribute('data-tache-id'); cell.dataset.heures = payload.heures || ''; cell.innerHTML = `<span class="text-muted small">+ Tâche</span>`; }
        bootstrap.Modal.getInstance(modalEl)?.hide();
        return;
      }
      if (!j?.success) throw new Error(j?.message || 'Échec enregistrement');

      const selector = `[data-click-tache][data-user-id="${payload.utilisateur_id}"][data-date="${payload.date_jour}"]`;
      const cell = document.querySelector(selector);
      if (cell){
        if (payload.tache_id){
          cell.dataset.ptChantierId = String(payload.chantier_id || modalEl.dataset.cid || '');
          cell.dataset.tacheId = String(payload.tache_id);
          cell.dataset.heures = payload.heures;
          const lib = (j?.tache?.libelle ?? '').toString();
          cell.innerHTML = `<span class="badge bg-primary">${lib}</span>`;
        } else {
          cell.dataset.tacheId=''; cell.removeAttribute('data-tache-id'); cell.dataset.heures = payload.heures || '';
          cell.innerHTML = `<span class="text-muted small">+ Tâche</span>`;
        }
      }
      bootstrap.Modal.getInstance(modalEl)?.hide();
    }catch(err){
      if (payload.tache_id === 0){
        const selector = `[data-click-tache][data-user-id="${payload.utilisateur_id}"][data-date="${payload.date_jour}"]`;
        const cell = document.querySelector(selector);
        if (cell){ cell.dataset.tacheId=''; cell.removeAttribute('data-tache-id'); cell.dataset.heures = payload.heures || ''; cell.innerHTML = `<span class="text-muted small">+ Tâche</span>`; }
        bootstrap.Modal.getInstance(modalEl)?.hide();
        return;
      }
      alert(err.message || err);
    }
  });
})();
