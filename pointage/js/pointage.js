// /pointage/js/pointage.js
document.addEventListener('DOMContentLoaded', () => {
  const app            = document.getElementById('pointageApp');
  const table          = app?.querySelector('table');
  const searchInput    = document.getElementById('searchInput');
  const filtersBar     = document.getElementById('chantierFilters');
  const camionControls = document.getElementById('camionControls');
  const thead          = table?.querySelector('thead');

  // ---- Modal Absence
  const absenceModalEl = document.getElementById('absenceModal');
  const absenceModal   = absenceModalEl ? new bootstrap.Modal(absenceModalEl) : null;
  const absForm        = document.getElementById('absenceForm');
  const absSaveBtn     = document.getElementById('absenceSave');

  const labelForReason = (r) => (r === 'conges' ? 'Congés' : (r === 'maladie' ? 'Maladie' : 'Injustifié'));

  // ---- Jours de la semaine (via data-iso sur les <th>)
  const headerThs = Array.from(document.querySelectorAll('thead tr th[data-iso]')); // seulement ceux qui ont data-iso
  const dayIsos   = headerThs.map(th => th.dataset.iso);

  // Helper fetch encodé x-www-form-urlencoded
  async function postForm(url, payload) {
    const body = new URLSearchParams();
    Object.entries(payload || {}).forEach(([k, v]) => {
      if (v !== undefined && v !== null) body.append(k, String(v));
    });
    const res = await fetch(url, { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body });
    let data = null;
    try { data = await res.json(); } catch { data = null; }
    if (!res.ok || !data || data.success === false) {
      const m = (data && (data.message || data.msg)) || `Erreur (${res.status})`;
      throw new Error(m);
    }
    return data;
  }

  // ISO « local » (évite le décalage UTC)
  function todayLocalISO() {
    const d = new Date();
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 10);
  }
  const todayIso  = todayLocalISO();

  // ❌ plus de fallback sur lundi
  let activeDay   = dayIsos.includes(todayIso) ? todayIso : null;

  // ---- Filtre chantier + recherche
  let activeChantier =
    (filtersBar?.querySelector('button.active[data-chantier]') || { dataset: { chantier: 'all' } })
      .dataset.chantier || 'all';
  let query = '';

  // ---- Cache des capacités camions
  const truckCap = new Map(); // key `${chantierId}|${dateIso}` -> nb
  const capKey = (c, d) => `${c}|${d}`;
  const getCap = (c, d) => (truckCap.get(capKey(c,d)) ?? 1);

  // ---- Helpers d'affichage
  function getActiveColIndex() {
    if (!activeDay) return -1;
    const idx = dayIsos.indexOf(activeDay);
    return idx === -1 ? -1 : (idx + 1); // +1 car col 0 = "Employés"
  }

  function highlightActiveDay() {
    const colIndex = getActiveColIndex();
    document.querySelectorAll('.day-active').forEach(el => el.classList.remove('day-active'));
    if (colIndex < 0) return; // rien à surligner

    const th = thead?.querySelectorAll('tr th')[colIndex];
    if (th) th.classList.add('day-active');
    document.querySelectorAll('tbody tr').forEach(tr => {
      const tds = tr.querySelectorAll('td');
      const td  = tds[colIndex];
      if (td) td.classList.add('day-active');
    });
  }

  function rowMatches(tr) {
    // Filtre chantier : seulement si on a un jour actif
    if (activeChantier !== 'all' && activeDay) {
      const cell    = tr.querySelector(`td[data-date="${activeDay}"]`);
      if (!cell) return false;
      const planned = (cell.dataset.plannedChantiersDay || '').split(',').filter(Boolean);
      if (!planned.includes(String(activeChantier))) return false;
    }
    // Recherche texte
    if (query) {
      const name = (tr.dataset.name || '');
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

  // ======================================================================
  //               RÉGLETTE CAMIONS (SOUS LES BOUTONS CHANTIERS)
  // ======================================================================
  async function loadAndShowCamionControls(chantierId, dateIso){
    try {
      const data = await postForm('/pointage/pointage_camion.php', { action:'get', chantier_id:chantierId, date_jour:dateIso });
      truckCap.set(capKey(chantierId, dateIso), data.nb); // MAJ cache

      camionControls.innerHTML = `
        <div class="d-flex align-items-center gap-2">
          <span class="fw-semibold">Camions :</span>
          <button type="button" class="btn btn-sm btn-outline-secondary camion-dec">−</button>
          <span class="camion-count fw-bold">${data.nb}</span>
          <button type="button" class="btn btn-sm btn-outline-secondary camion-inc">+</button>
        </div>
      `;

      camionControls.querySelector('.camion-dec')?.addEventListener('click', () => updateCamion('dec', chantierId, dateIso));
      camionControls.querySelector('.camion-inc')?.addEventListener('click', () => updateCamion('inc', chantierId, dateIso));
    } catch(err){ alert(err.message); }
  }

  async function updateCamion(action, chantierId, dateIso){
    try {
      const data = await postForm('/pointage/pointage_camion.php', { action, chantier_id:chantierId, date_jour:dateIso });
      camionControls.querySelector('.camion-count').textContent = String(data.nb);
      truckCap.set(capKey(chantierId, dateIso), data.nb); // MAJ cache IMMÉDIATE
    } catch(err){ alert(err.message); }
  }

  // Afficher/cacher la réglette quand on change de chantier
  filtersBar?.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-chantier]');
    if (!btn) return;

    filtersBar.querySelectorAll('button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    activeChantier = btn.dataset.chantier; // 'all' ou id

    if (activeChantier !== 'all' && activeDay){
      loadAndShowCamionControls(activeChantier, activeDay);
    } else {
      camionControls.innerHTML = '';
    }
    applyFilter();
  });

  // Changement de jour actif (utilise data-iso sur le <th>)
  thead?.addEventListener('click', (e) => {
    const th  = e.target.closest('th[data-iso]');
    const iso = th?.dataset?.iso;
    if (!iso) return;
    activeDay = iso;
    if (activeChantier !== 'all'){
      loadAndShowCamionControls(activeChantier, activeDay);
    }
    applyFilter();
  });

  // Recherche texte
  searchInput?.addEventListener('input', () => {
    query = (searchInput.value || '').toLowerCase();
    applyFilter();
  });

  // ======================================================================
  //                         ACTIONS : PRÉSENCE / ABSENCE
  // ======================================================================

  // Présent 8h15 (toggle via pointage_present.php)
  table?.addEventListener('click', async (e) => {
    const btn = e.target.closest('.present-btn');
    if (!btn) return;

    const td      = btn.closest('td');
    const tr      = btn.closest('tr');
    const userId  = tr.dataset.userId;
    const dateIso = td.dataset.date;
    const hours   = btn.dataset.hours || '8.25';

    const isActive = btn.classList.contains('btn-success');

    try {
      if (isActive) {
        // -> DECOCHER Présent : hours = 0
        await postForm('/pointage/pointage_present.php', { utilisateur_id:userId, date:dateIso, hours:'0' });
        btn.classList.remove('btn-success'); btn.classList.add('btn-outline-success');
        btn.textContent = 'Présent 8h15';
        return;
      }

      // -> ACTIVER Présent
      // 1) d'abord retirer une éventuelle **absence** côté serveur (le endpoint présent le fait aussi, mais on “optimise” l’UI)
      try { await postForm('/pointage/pointage_absence.php', { utilisateur_id:userId, date:dateIso, remove:'1' }); } catch {}

      // reset UI absence
      const absBtn = td.querySelector('.absence-btn');
      if (absBtn) { absBtn.classList.remove('btn-danger'); absBtn.classList.add('btn-outline-danger'); absBtn.textContent = 'Abs.'; }
      td.querySelector('.absence-pill')?.remove();
      td.querySelectorAll('.conduite-btn').forEach(b => b.disabled = false);

      // 2) enregistrer la présence
      await postForm('/pointage/pointage_present.php', { utilisateur_id:userId, date:dateIso, hours });

      // 3) UI présent -> vert
      btn.classList.remove('btn-outline-success'); btn.classList.add('btn-success');
      btn.textContent = 'Présent 8h15';

    } catch (err) {
      alert(err.message || 'Erreur');
    }
  });

  // Ouvrir la modale au clic sur "Abs."
  table?.addEventListener('click', (e) => {
    const trigger = e.target.closest('.absence-btn');
    if (!trigger) return;

    const td = trigger.closest('td');
    const tr = trigger.closest('tr');
    absenceModalEl._targetCell = td;

    document.getElementById('absUserId').value = tr.dataset.userId;
    document.getElementById('absDate').value   = td.dataset.date;
    absForm.reason.value = 'conges';
    document.getElementById('absHours').value = '8.25';

    absenceModal?.show();
  });

  // Enregistrer la modale (pose / maj absence via pointage_absence.php)
  absSaveBtn?.addEventListener('click', async () => {
    if (!absenceModalEl?._targetCell) return;
    const td      = absenceModalEl._targetCell;
    const userId  = document.getElementById('absUserId').value;
    const dateIso = document.getElementById('absDate').value;
    const reason  = absForm.reason.value;
    const hours   = parseFloat(document.getElementById('absHours').value || '0');

    if (!hours || hours < 0.25 || hours > 8.25) {
      alert('Saisis un nombre d’heures valide (0.25 à 8.25).');
      return;
    }

    try {
      await postForm('/pointage/pointage_absence.php', {
        utilisateur_id: userId, date: dateIso, reason, hours: String(hours)
      });

      // 1) bouton Abs. rouge + texte
      const absBtn = td.querySelector('.absence-btn');
      if (absBtn) {
        absBtn.classList.remove('btn-outline-danger');
        absBtn.classList.add('btn-danger');
        absBtn.textContent = 'Abs. ' + labelForReason(reason);
      }

      // 2) badge d’état + heures
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

      // 3) on ne garde PAS "Présent" actif en même temps
      const present = td.querySelector('.present-btn');
      if (present) {
        present.classList.remove('btn-success');
        present.classList.add('btn-outline-success');
        present.textContent = 'Présent 8h15';
      }

      // 4) conduite : absence => A ET R désactivés (quel que soit motif/temps)
      td.querySelectorAll('.conduite-btn').forEach(b => b.disabled = true);

      absenceModal?.hide();

    } catch (err) {
      alert(err.message || 'Erreur');
    }
  });

  // Décocher une absence existante (bouton rouge -> toggle off via remove=1)
  table?.addEventListener('click', async (e) => {
    const btn = e.target.closest('.absence-btn');
    if (!btn || !btn.classList.contains('btn-danger')) return;

    const td      = btn.closest('td');
    const tr      = btn.closest('tr');
    const userId  = tr.dataset.userId;
    const dateIso = td.dataset.date;

    try {
      await postForm('/pointage/pointage_absence.php', { utilisateur_id:userId, date:dateIso, remove:'1' });

      btn.classList.remove('btn-danger'); btn.classList.add('btn-outline-danger');
      btn.textContent = 'Abs.';
      td.querySelector('.absence-pill')?.remove();
      // => Réactiver conduite A et R
      td.querySelectorAll('.conduite-btn').forEach(b => b.disabled = false);

    } catch (err) { alert(err.message || 'Erreur'); }
  });

  // ======================================================================
  //                         CONDUITE A/R  (avec capacité)
  // ======================================================================
  table?.addEventListener('click', async (e) => {
    const btn = e.target.closest('.conduite-btn');
    if (!btn) return;
    if (btn.disabled) return;

    const td      = btn.closest('td');
    const tr      = btn.closest('tr');
    const userId  = tr.dataset.userId;
    const dateIso = td.dataset.date;
    const type    = btn.dataset.type; // 'A' | 'R'

    const planned    = (td.dataset.plannedChantiersDay || '').split(',').filter(Boolean);
    const chantierId = planned[0] || null;
    if (!chantierId) { alert("Aucun chantier planifié ce jour."); return; }

    const cap      = getCap(chantierId, dateIso);
    const isActive = btn.classList.contains(type === 'A' ? 'btn-primary' : 'btn-success');

    try {
      if (!isActive) {
        // Activer
        const data = await postForm('/pointage/pointage_conduite.php', {
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
        // Désactiver
        await postForm('/pointage/pointage_conduite.php', {
          chantier_id: chantierId, type, utilisateur_id: userId, date_pointage: dateIso, remove:'1'
        });

        if (type === 'A') {
          btn.classList.remove('btn-primary'); btn.classList.add('btn-outline-primary');
        } else {
          btn.classList.remove('btn-success'); btn.classList.add('btn-outline-success');
        }
      }
    } catch (err) { alert(err.message || 'Erreur conduite'); }
  });

  // ======================================================================
  //                               INIT
  // ======================================================================
  if (activeChantier !== 'all' && activeDay) {
    loadAndShowCamionControls(activeChantier, activeDay);
  }
  applyFilter();
});
