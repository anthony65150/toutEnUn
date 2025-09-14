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
    if (activeChantier !== 'all' && activeDay) {
      loadAndShowCamionControls(activeChantier, activeDay);
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

    if (activeChantier !== 'all' && activeDay) {
      loadAndShowCamionControls(activeChantier, activeDay);
    } else if (camionControls) {
      camionControls.innerHTML = '';
    }

    applyFilter();
  });

  /* ==============================
   *   RÉGLETTE CAMIONS (capacité)
   * ============================== */
  const truckCap = new Map(); // key `${chantierId}|${dateIso}` -> nb
  const capKey = (c, d) => `${c}|${d}`;
  const getCap = (c, d) => (truckCap.get(capKey(c, d)) ?? 1);

  // Lecture silencieuse (aucune alerte si le PHP renvoie autre chose qu’un JSON)
  async function postFormSilent(url, payload) {
    try {
      const body = new URLSearchParams();
      Object.entries(payload || {}).forEach(([k, v]) => body.append(k, v == null ? '' : String(v)));
      const res  = await fetch(url, { method: 'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
      const text = await res.text();

      // tolère un nombre simple (ex: "2")
      const num = Number(text);
      if (!Number.isNaN(num)) return { success:true, nb:num };

      let data = null;
      try { data = JSON.parse(text); } catch { data = null; }
      if (data === 'ok') data = { success:true, nb:1 };

      if (!res.ok || !data || data.success === false) {
        if (DEBUG) console.warn('camion(get) non-JSON/KO', { url, payload, status:res?.status, text });
        return null;
      }
      return data;
    } catch (e) {
      if (DEBUG) console.warn('camion(get) network?', { url, payload, err:e });
      return null;
    }
  }

  async function loadAndShowCamionControls(chantierId, dateIso) {
    if (!camionControls) return;
    const cid = asIntOrNull(chantierId);
    if (!cid || !dateIso) { camionControls.innerHTML = ''; return; }

    // tente date_jour puis date — silencieux
    let data = await postFormSilent('/pointage/pointage_camion.php', { action:'get', chantier_id:cid, date_jour:dateIso });
    if (!data) data = await postFormSilent('/pointage/pointage_camion.php', { action:'get', chantier_id:cid, date:dateIso });
    if (!data) { camionControls.innerHTML = ''; return; }

    const nb = Number(data.nb) || 1;
    truckCap.set(capKey(cid, dateIso), nb);

    camionControls.innerHTML = `
      <div class="d-flex align-items-center gap-2">
        <span class="fw-semibold">Camions :</span>
        <button type="button" class="btn btn-sm btn-outline-secondary camion-dec" aria-label="Diminuer">−</button>
        <span class="camion-count fw-bold" aria-live="polite">${nb}</span>
        <button type="button" class="btn btn-sm btn-outline-secondary camion-inc" aria-label="Augmenter">+</button>
      </div>
    `;

    camionControls.querySelector('.camion-dec')?.addEventListener('click', () => updateCamion('dec', cid, dateIso));
    camionControls.querySelector('.camion-inc')?.addEventListener('click', () => updateCamion('inc', cid, dateIso));
  }

  async function updateCamion(action, chantierId, dateIso) {
    if (!camionControls) return;
    const cid = asIntOrNull(chantierId);
    if (!cid || !dateIso) return;
    try {
      const data = await postForm('/pointage/pointage_camion.php', { action, chantier_id: cid, date_jour: dateIso });
      const span = camionControls.querySelector('.camion-count');
      const nb   = Number(data.nb) || 1;
      if (span) span.textContent = String(nb);
      truckCap.set(capKey(cid, dateIso), nb);
    } catch (err) {
      showError(err.message, err.debug);
    }
  }

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

    try {
      if (isActive) {
        await postForm('/pointage/pointage_present.php', { utilisateur_id: userId, date: dateIso, hours: '0' });
        btn.classList.remove('btn-success'); btn.classList.add('btn-outline-success');
        btn.textContent = 'Présent 8h15';
        return;
      }

      // activer Présent → retirer absence éventuelle
      try { await postForm('/pointage/pointage_absence.php', { utilisateur_id: userId, date: dateIso, remove: '1' }); } catch {}

      // reset UI absence
      const absBtn = td.querySelector('.absence-btn');
      if (absBtn) { absBtn.classList.remove('btn-danger'); absBtn.classList.add('btn-outline-danger'); absBtn.textContent = 'Abs.'; }
      td.querySelector('.absence-pill')?.remove();
      td.querySelectorAll('.conduite-btn').forEach(b => b.disabled = false);

      await postForm('/pointage/pointage_present.php', { utilisateur_id: userId, date: dateIso, hours });

      btn.classList.remove('btn-outline-success'); btn.classList.add('btn-success');
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
    const fUser   = document.getElementById('absUserId');
    const fDate   = document.getElementById('absDate');
    const fHours  = document.getElementById('absHours');
    const userId  = fUser?.value || '';
    const dateIso = fDate?.value  || '';
    const reason  = absForm?.reason?.value || 'injustifie';
    const hours   = parseFloat((fHours?.value || '0'));

    if (!userId || !dateIso) return;
    if (!hours || hours < 0.25 || hours > 8.25) { showError('Saisis un nombre d’heures valide (0.25 à 8.25).'); return; }

    try {
      await postForm('/pointage/pointage_absence.php', {
        utilisateur_id: userId, date: dateIso, reason, hours: String(hours)
      });

      const absBtn = td.querySelector('.absence-btn');
      if (absBtn) {
        absBtn.classList.remove('btn-outline-danger');
        absBtn.classList.add('btn-danger');
        absBtn.textContent = 'Abs. ' + labelForReason(reason);
      }

      let pill = td.querySelector('.absence-pill');
      if (!pill) {
        pill = document.createElement('button');
        pill.type = 'button';
        pill.className = 'btn btn-sm absence-pill ms-1';
        const presentBtn = td.querySelector('.present-btn');
        if (presentBtn) presentBtn.after(pill); else td.prepend(pill);
      }
      pill.className = 'btn btn-sm absence-pill ms-1 ' + (
        reason === 'conges' ? 'btn-warning' : reason === 'maladie' ? 'btn-info' : 'btn-secondary'
      );
      pill.textContent = `${labelForReason(reason)} ${hours.toString().replace('.', ',')} h`;

      const present = td.querySelector('.present-btn');
      if (present) { present.classList.remove('btn-success'); present.classList.add('btn-outline-success'); present.textContent = 'Présent 8h15'; }

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

    try {
      await postForm('/pointage/pointage_absence.php', { utilisateur_id: userId, date: dateIso, remove: '1' });

      btn.classList.remove('btn-danger'); btn.classList.add('btn-outline-danger');
      btn.textContent = 'Abs.';
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

    const planned     = (td.dataset.plannedChantiersDay || '').split(',').filter(Boolean);
    const chantierId  = asIntOrNull(planned[0]);
    if (!chantierId) { showError("Aucun chantier planifié ce jour."); return; }

    const cap      = getCap(chantierId, dateIso);
    const isActive = btn.classList.contains(type === 'A' ? 'btn-primary' : 'btn-success');

    try {
      if (!isActive) {
        await postForm('/pointage/pointage_conduite.php', {
          chantier_id: chantierId, type, utilisateur_id: userId, date_pointage: dateIso
        });

        if (type === 'A') {
          btn.classList.remove('btn-outline-primary'); btn.classList.add('btn-primary');
          if (cap === 1) {
            document.querySelectorAll(`#pointageApp tbody tr td[data-date="${dateIso}"]`).forEach(otherTd => {
              if (otherTd === td) return;
              const chs = (otherTd.dataset.plannedChantiersDay || '').split(',').filter(Boolean);
              if (chs.includes(String(chantierId))) {
                const otherA = otherTd.querySelector('.conduite-btn[data-type="A"]');
                if (otherA) { otherA.classList.remove('btn-primary'); otherA.classList.add('btn-outline-primary'); }
              }
            });
          }
        } else {
          btn.classList.remove('btn-outline-success'); btn.classList.add('btn-success');
          if (cap === 1) {
            document.querySelectorAll(`#pointageApp tbody tr td[data-date="${dateIso}"]`).forEach(otherTd => {
              if (otherTd === td) return;
              const chs = (otherTd.dataset.plannedChantiersDay || '').split(',').filter(Boolean);
              if (chs.includes(String(chantierId))) {
                const otherR = otherTd.querySelector('.conduite-btn[data-type="R"]');
                if (otherR) { otherR.classList.remove('btn-success'); otherR.classList.add('btn-outline-success'); }
              }
            });
          }
        }
      } else {
        await postForm('/pointage/pointage_conduite.php', {
          chantier_id: chantierId, type, utilisateur_id: userId, date_pointage: dateIso, remove: '1'
        });

        if (type === 'A') {
          btn.classList.remove('btn-primary'); btn.classList.add('btn-outline-primary');
        } else {
          btn.classList.remove('btn-success'); btn.classList.add('btn-outline-success');
        }
      }
    } catch (err) {
      showError(err.message, err.debug);
    }
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
  if (activeChantier !== 'all' && activeDay) {
    loadAndShowCamionControls(activeChantier, activeDay);
  } else if (camionControls) {
    camionControls.innerHTML = '';
  }

  prefillChefChantierFromPlanning();
  applyFilter();
});
