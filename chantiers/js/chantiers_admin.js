/*******************************
 * chantiers_admin.js (corrigé)
 *******************************/

/* ========= 1) BASE & API (absolus) ========= */
// Option 1 (recommandé) : dans ton PHP -> <body data-base="/chantiers">
const BODY_BASE = (document.body && document.body.dataset && document.body.dataset.base) || '';

function detectBase() {
  if (BODY_BASE) return BODY_BASE;                // prioritaire si fourni
  // Essaie d’attraper “…/chantiers” dans l’URL, peu importe le sous-dossier
  const m = location.pathname.match(/^(.*\/chantiers)(?:\/|$)/i);
  if (m && m[1]) return m[1];
  // Fallback: si la page est directement sous /chantiers/
  if (location.pathname.startsWith('/chantiers/')) return '/chantiers';
  // Dernier recours: racine (si tu as déplacé le dossier)
  return '';
}
// base du module (tu peux aussi l’injecter depuis le PHP via window.APP_BASE)
const BASE = '/chantiers';

// bon chemin pour le toggle d'état
const API_TOGGLE = `${BASE}/chantiers_api.php`;

// trajet_api.php est bien dans /chantiers/services d’après ta capture
const API_TRAJET = `${BASE}/services/trajet_api.php`;


/* ========= 2) Toast ========= */
function showChantierToast(message = 'Chantier enregistré avec succès') {
  const toastEl = document.getElementById('chantierToast');
  const toastMsg = document.getElementById('chantierToastMsg');
  if (!toastEl || !toastMsg || !window.bootstrap) return alert(message);
  toastMsg.textContent = message;
  new bootstrap.Toast(toastEl).show();
}
window.showChantierToast = showChantierToast;

/* ========= 3) Recherche + "aucun résultat" ========= */
(function initSearch() {
  const input = document.getElementById('chantierSearchInput');
  const tbody = document.getElementById('chantiersTableBody');
  if (!input || !tbody) return;

  const table = tbody.closest('table');

  const ensureNoRow = () => {
    let tr = document.getElementById('noResultsChantier');
    if (!tr) {
      tr = document.createElement('tr');
      tr.id = 'noResultsChantier';
      tr.className = 'd-none';
      tr.appendChild(document.createElement('td'));
      tbody.appendChild(tr);
    }
    const thCount =
      (table?.tHead?.rows?.[0]?.cells?.length) ||
      (table ? table.querySelectorAll('thead th').length : 1) || 1;
    const td = tr.firstElementChild;
    td.colSpan = thCount;
    td.className = 'text-muted text-center py-4';
    td.textContent = 'Aucun chantier trouvé';
    return tr;
  };

  const noRow = ensureNoRow();
  const normalize = (s) => (s || '').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
  const getRows = () => Array.from(tbody.querySelectorAll('tr[data-row-id]'));

  const filter = () => {
    const q = normalize(input.value);
    let visible = 0;
    getRows().forEach(tr => {
      const show = !q || normalize(tr.textContent).includes(q);
      tr.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    ensureNoRow().classList.toggle('d-none', visible !== 0);
  };

  let t;
  input.addEventListener('input', () => { clearTimeout(t); t = setTimeout(filter, 120); });
  filter();
})();

/* ========= 4) Modale Édition : pré-remplissage ========= */
document.addEventListener('DOMContentLoaded', () => {
  const editModalEl = document.getElementById('chantierEditModal');
  if (!editModalEl) return;

  editModalEl.addEventListener('show.bs.modal', (ev) => {
    const btn = ev.relatedTarget;
    if (!btn) return;

    const form = document.getElementById('chantierEditForm');
    if (!form) return;

    const id    = btn.getAttribute('data-id') || '';
    const nom   = btn.getAttribute('data-nom') || '';
    const desc  = btn.getAttribute('data-description') || '';
    const debut = btn.getAttribute('data-debut') || '';
    const fin   = btn.getAttribute('data-fin') || '';
    const adr   = btn.getAttribute('data-adresse') || '';

    const chefIdsStr = btn.getAttribute('data-chef-ids') || '';
    const singleChef = btn.getAttribute('data-chef') || '';
    const targetIds = chefIdsStr
      ? chefIdsStr.split(',').map(s => s.trim()).filter(Boolean)
      : (singleChef ? [String(singleChef)] : []);

    form.querySelector('#chantierIdEdit').value    = id;
    form.querySelector('#chantierNomEdit').value   = nom;
    form.querySelector('#chantierDescEdit').value  = desc;
    form.querySelector('#chantierDebutEdit').value = debut;
    form.querySelector('#chantierFinEdit').value   = fin;
    const adrInput = form.querySelector('#chantierAdresseEdit');
    if (adrInput) adrInput.value = adr;

    const select = form.querySelector('#chefChantierEdit');
    if (select) {
      Array.from(select.options).forEach(opt => { opt.selected = targetIds.includes(opt.value); });
      if (!select.multiple && targetIds.length) select.value = targetIds[0];
    }

    const agenceId = btn.getAttribute('data-agence-id') || '0';
    const selAgenceEdit = form.querySelector('#chantierAgenceEdit');
    if (selAgenceEdit) {
      selAgenceEdit.value = [...selAgenceEdit.options].some(o => o.value === agenceId) ? agenceId : '0';
    }
  });
});

/* ========= 5) Délégation création/suppression ========= */
document.addEventListener('click', (ev) => {
  const delBtn    = ev.target.closest('.delete-btn');
  const createBtn = ev.target.closest('[data-bs-target="#chantierModal"]');

  if (delBtn) {
    const id = delBtn.dataset.id || '';
    const delForm = document.getElementById('deleteForm');
    if (delForm) delForm.querySelector('#deleteId').value = id;
    return;
  }

  if (createBtn) {
    const form = document.getElementById('chantierForm');
    if (form) {
      form.reset();
      const idField = document.getElementById('chantierId');
      if (idField) idField.value = '';
      const select = document.getElementById('chefChantier');
      if (select) Array.from(select.options).forEach(o => o.selected = false);
      const lbl = document.getElementById('chantierModalLabel');
      if (lbl) lbl.textContent = 'Créer un chantier';
      const adrNew = document.getElementById('chantierAdresse');
      if (adrNew) adrNew.value = '';
    }
    const selAgenceNew = document.getElementById('chantierAgence');
    if (selAgenceNew) {
      const filtersWrap = document.getElementById('agenceFilters');
      const activeVal = filtersWrap?.querySelector('button.active')?.dataset.agence || '0';
      selAgenceNew.value = (activeVal !== 'none' && /^\d+$/.test(activeVal) &&
                            [...selAgenceNew.options].some(o => o.value === activeVal))
                          ? activeVal : '0';
    }
  }
});

/* ========= 6) Google Places (adresse, sans geometry) ========= */
(function () {
  let ready = false;
  const waitMaps = () => new Promise(r => {
    if (ready && window.google?.maps?.places) return r(true);
    (function check(){ (window.google?.maps?.places) ? (ready=true,r(true)) : setTimeout(check,80); })();
  });

  function wireAutocomplete(input) {
    if (!input || !window.google?.maps?.places) return;
    const ac = new google.maps.places.Autocomplete(input, {
      fields: ['formatted_address'],
      types: ['geocode']
    });
    ac.addListener('place_changed', () => {
      const p = ac.getPlace();
      if (p?.formatted_address) input.value = p.formatted_address;
    });
    input.addEventListener('keydown', e => { if (e.key === 'Enter') e.preventDefault(); });
  }

  document.addEventListener('show.bs.modal', async (ev) => {
    if (ev.target.matches('#chantierModal'))     { await waitMaps(); wireAutocomplete(document.getElementById('chantierAdresse')); }
    if (ev.target.matches('#chantierEditModal')) { await waitMaps(); wireAutocomplete(document.getElementById('chantierAdresseEdit')); }
  });
})();

/* ========= 7) Calcul du trajet ========= */
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('.calc-trajet-btn');
  if (!btn) return;

  const tr   = btn.closest('tr');
  const cid  = btn.getAttribute('data-id');
  const dep  = btn.getAttribute('data-depot-id') || '';

  btn.disabled = true;
  const save = btn.innerHTML;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

  try {
    const fd = new FormData();
    fd.set('action', 'compute');
    fd.set('chantier_id', cid);
    if (dep && dep !== '0') fd.set('depot_id', dep);

    const res  = await fetch(API_TRAJET, { method:'POST', body: fd, credentials:'same-origin' });
    const text = await res.text();
    const ct   = res.headers.get('content-type') || '';
    if (!ct.includes('application/json')) throw new Error('Réponse non JSON');
    const json = JSON.parse(text);
    if (!res.ok || !json.ok) throw new Error(json.error || 'Erreur trajet');

    if (json.skipped) { showChantierToast('Trajet déjà à jour (moins de 24h)'); return; }

    const km  = (Number(json.distance_m) / 1000).toFixed(1).replace('.', ',');
    const min = Math.round(Number(json.duration_s) / 60);

    tr.querySelector('.trajet-km')?.replaceChildren(document.createTextNode(km));
    tr.querySelector('.trajet-min')?.replaceChildren(document.createTextNode(min + ' min'));

    tr.classList.add('table-success'); setTimeout(() => tr.classList.remove('table-success'), 1200);
    showChantierToast(`Trajet mis à jour : ${km} km · ${min} min`);
  } catch (err) {
    alert(err.message || err);
  } finally {
    btn.innerHTML = save;
    btn.disabled = false;
  }
});

/* ========= 8) Filtres agence + état + compteurs ========= */
(function () {
  let bound = false;

  function initChantierFilters() {
    if (bound) return;
    const filtersWrap = document.getElementById('agenceFilters');
    const etatWrap    = document.getElementById('etatFilters');
    const tbody       = document.getElementById('chantiersTableBody');
    const search      = document.getElementById('chantierSearchInput');
    if (!filtersWrap || !tbody) return;
    bound = true;

    const rows = Array.from(tbody.querySelectorAll('tr[data-row-id]'));

    const setActive = (groupEl, attr, value) => {
      if (!groupEl) return;
      groupEl.querySelectorAll('button').forEach(b => {
        b.classList.toggle('active', b.dataset[attr] === value);
      });
    };

    const normalize = (s) =>
      (s || '').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();

    let currentAgence = '0';
    let currentEtat   = 'en_cours';

    const matchAgence = tr => {
      const agid = parseInt(tr.getAttribute('data-agence-id') || '0', 10);
      if (currentAgence === '0') return true;
      if (currentAgence === 'none') return (!agid || agid === 0);
      return agid === parseInt(currentAgence, 10);
    };
    const matchEtat = tr => {
      if (!etatWrap || etatWrap.classList.contains('d-none')) return true;
      const etat = (tr.getAttribute('data-etat') || 'en_cours').toLowerCase();
      return etat === currentEtat;
    };
    const matchSearch = tr => {
      const q = normalize(search?.value || '');
      if (!q) return true;
      return normalize(tr.textContent).includes(q);
    };

    const applyFilters = () => {
      rows.forEach(tr => {
        tr.style.display = (matchAgence(tr) && matchEtat(tr) && matchSearch(tr)) ? '' : 'none';
      });
    };

    function setEtatCount(which, val) {
      if (!etatWrap) return;
      const span = etatWrap.querySelector(`.etat-count[data-for="${which}"]`);
      if (span) span.textContent = String(val);
      else {
        const btn = etatWrap.querySelector(`button[data-etat="${which}"]`);
        if (btn) btn.textContent = (which === 'fini') ? `Fini (${val})` : `En cours (${val})`;
      }
    }
    function recomputeEtatCounts() {
      let enCours = 0, fini = 0;
      rows.forEach(tr => {
        if (!matchAgence(tr)) return;
        const etat = (tr.getAttribute('data-etat') || 'en_cours').toLowerCase();
        if (etat === 'fini') fini++; else enCours++;
      });
      setEtatCount('en_cours', enCours);
      setEtatCount('fini', fini);
    }

    const updateEtatVisibility = () => {
      if (!etatWrap) return;
      const visible = (currentAgence !== '0');
      etatWrap.classList.toggle('d-none', !visible);
      if (visible) recomputeEtatCounts();
      applyFilters();
    };

    filtersWrap.addEventListener('click', (e) => {
      const btn = e.target.closest('button[data-agence]');
      if (!btn) return;
      currentAgence = btn.dataset.agence;
      setActive(filtersWrap, 'agence', currentAgence);

      const url = new URL(window.location.href);
      if (currentAgence === '0') url.searchParams.delete('agence_id');
      else url.searchParams.set('agence_id', currentAgence);
      window.history.replaceState({}, '', url.toString());

      updateEtatVisibility();
    });

    if (etatWrap) {
      etatWrap.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-etat]');
        if (!btn) return;
        currentEtat = btn.dataset.etat;
        setActive(etatWrap, 'etat', currentEtat);

        const url = new URL(window.location.href);
        url.searchParams.set('etat', currentEtat);
        window.history.replaceState({}, '', url.toString());

        applyFilters();
        recomputeEtatCounts();
      });
    }

    if (search) search.addEventListener('input', applyFilters);

    (function initFromURL() {
      const url = new URL(window.location.href);
      const qsAgence = url.searchParams.get('agence_id');
      const qsEtat   = url.searchParams.get('etat');

      if (qsAgence) currentAgence = qsAgence;
      setActive(filtersWrap, 'agence', currentAgence);

      if (etatWrap) {
        if (qsEtat && ['en_cours','fini'].includes(qsEtat)) currentEtat = qsEtat;
        setActive(etatWrap, 'etat', currentEtat);
      }

      updateEtatVisibility();
    })();

    document.addEventListener('chantier:etat-changed', () => {
      recomputeEtatCounts();
      applyFilters();
    });
  }

  document.addEventListener('DOMContentLoaded', initChantierFilters);
  window.addEventListener('pageshow', initChantierFilters);
})();

/* ========= 9) Bascule état ========= */
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('.toggle-etat-btn');
  if (!btn) return;

  const tr  = btn.closest('tr');
  const id  = btn.dataset.id;

  btn.disabled = true;
  const oldHTML = btn.innerHTML;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

  try {
    const fd = new FormData();
    fd.set('action', 'toggle_etat');
    fd.set('id', id);
    if (window.CSRF_TOKEN) fd.set('csrf_token', window.CSRF_TOKEN);

    const res  = await fetch(API_TOGGLE, { method:'POST', body: fd, credentials:'same-origin' });
    const text = await res.text();            // lire UNE seule fois
    let json;
    try { json = JSON.parse(text); }
    catch (e2) {
      console.error('Réponse brute API_TOGGLE:', text);
      throw new Error('Réponse API non JSON');
    }
    if (!res.ok || !json.ok) throw new Error(json.error || 'Erreur bascule état');

    const newEtat = json.etat;
    tr.dataset.etat  = newEtat;
    btn.dataset.etat = newEtat;

    if (newEtat === 'fini') {
      btn.classList.remove('btn-outline-success');
      btn.classList.add('btn-outline-warning');
      btn.title = 'Repasser en cours';
      btn.innerHTML = '<i class="bi bi-arrow-counterclockwise"></i>';
    } else {
      btn.classList.remove('btn-outline-warning');
      btn.classList.add('btn-outline-success');
      btn.title = 'Marquer comme fini';
      btn.innerHTML = '<i class="bi bi-check2-circle"></i>';
    }

    showChantierToast(newEtat === 'fini' ? 'Chantier marqué comme fini' : 'Chantier repassé en cours');
    document.dispatchEvent(new CustomEvent('chantier:etat-changed'));
  } catch (err) {
    alert(err.message || err);
    btn.innerHTML = oldHTML;
  } finally {
    btn.disabled = false;
  }
});
