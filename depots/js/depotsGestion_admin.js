document.addEventListener('DOMContentLoaded', () => {
  const createForm = document.getElementById('formDepotCreate');
  const editForm   = document.getElementById('formDepotEdit');
  const deleteForm = document.getElementById('formDepotDelete');

  const modalEditEl = document.getElementById('modalDepotEdit');
  const modalDelEl  = document.getElementById('modalDepotDelete');
  const modalEdit   = modalEditEl ? new bootstrap.Modal(modalEditEl) : null;
  const modalDel    = modalDelEl ? new bootstrap.Modal(modalDelEl) : null;

  // Endpoint API (relatif à /depots/depots_admin.php)
  const API_URL = 'depots_api.php';

  // Utilitaire fetch JSON avec meilleure gestion d'erreurs
  const postForm = async (fd, submitBtn) => {
    if (submitBtn) submitBtn.disabled = true;
    try {
      const res = await fetch(API_URL, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      });

      let payload = null, fallbackText = '';
      try { payload = await res.json(); }
      catch { try { fallbackText = await res.text(); } catch {} }

      if (!res.ok || !payload?.ok) {
        const msg = payload?.error || (fallbackText ? fallbackText.substring(0, 200) : `HTTP ${res.status}`);
        throw new Error(msg);
      }
      return payload;
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
  };

  // Helpers redirection (garde la page /depots/depots_admin.php et ajoute ?success=...)
  const redirectWith = (paramsObj) => {
    const base = location.pathname.replace(/[\?#].*$/, '');
    const usp = new URLSearchParams(paramsObj);
    location.assign(`${base}?${usp.toString()}`);
  };

  // ===== CREATE =====
  createForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(createForm); // contient nom, responsable_id, adresse (NEW côté form)
    fd.set('action', 'create');
    const btn = createForm.querySelector('[type="submit"]');
    try {
      const j = await postForm(fd, btn);
      redirectWith({ success: 'create', ...(j.id ? { highlight: j.id } : {}) });
    } catch (err) {
      console.error(err);
      alert(err.message || 'Erreur création');
    }
  });

  // ===== OUVERTURE MODALE EDIT =====
  document.getElementById('depotsTbody')?.addEventListener('click', (e) => {
    const btn = e.target.closest('.edit-depot-btn');
    if (!btn) return;

    document.getElementById('editDepotId').value = btn.dataset.id || '';
    document.getElementById('editNom').value     = btn.dataset.nom || '';
    document.getElementById('editResp').value    =
      (btn.dataset.resp && btn.dataset.resp !== '0') ? btn.dataset.resp : '';

    // NEW: pré-remplir l'adresse
    const addrInput = document.getElementById('editAdresse');
    if (addrInput) addrInput.value = btn.dataset.adresse || '';

    modalEdit?.show();
  });

  // ===== UPDATE =====
  editForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(editForm);
    fd.set('action', 'update');
    fd.set('id', document.getElementById('editDepotId')?.value || '');
    fd.set('nom', document.getElementById('editNom')?.value || '');
    fd.set('responsable_id', document.getElementById('editResp')?.value ?? '');

    // NEW: forcer l'envoi de l'adresse (au cas où)
    fd.set('adresse', document.getElementById('editAdresse')?.value || '');

    const btn = editForm.querySelector('[type="submit"]');
    try {
      const j = await postForm(fd, btn);
      redirectWith({ success: 'update', ...(j.id ? { highlight: j.id } : {}) });
    } catch (err) {
      console.error(err);
      alert(err.message || 'Erreur mise à jour');
    }
  });

  // ===== OUVERTURE MODALE DELETE =====
  document.getElementById('depotsTbody')?.addEventListener('click', (e) => {
    const btn = e.target.closest('.delete-depot-btn');
    if (!btn) return;
    document.getElementById('deleteDepotId').value = btn.dataset.id || '';
    modalDel?.show();
  });

  // ===== DELETE =====
  deleteForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(deleteForm);
    fd.set('action', 'delete');
    const btn = deleteForm.querySelector('[type="submit"]');
    try {
      await postForm(fd, btn);
      redirectWith({ success: 'delete' });
    } catch (err) {
      console.error(err);
      alert(err.message || 'Erreur suppression');
    }
  });

  // ===== RECHERCHE DÉPÔTS =====
  const searchInput = document.getElementById('depotSearchInput');
  const tbody = document.getElementById('depotsTbody');

  if (searchInput && tbody) {
    let noRow = document.getElementById('noResultsRow');
    if (!noRow) {
      noRow = document.createElement('tr');
      noRow.id = 'noResultsRow';
      noRow.className = 'd-none';
      // NEW: colspan auto = nb de colonnes du tableau
      const thCount = document.querySelectorAll('table thead th').length || 4;
      noRow.innerHTML = `<td colspan="${thCount}" class="text-muted text-center py-4">Aucun dépôt trouvé</td>`;
      tbody.appendChild(noRow);
    }

    const rows = () => Array.from(tbody.querySelectorAll('tr')).filter(tr => tr !== noRow);
    const normalize = (s) =>
      (s || '')
        .toString()
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .trim();

    const filter = () => {
      const q = normalize(searchInput.value);
      let visible = 0;
      rows().forEach(tr => {
        const show = !q || normalize(tr.textContent).includes(q);
        tr.style.display = show ? '' : 'none';
        if (show) visible++;
      });
      noRow.classList.toggle('d-none', visible !== 0);
    };

    let t;
    searchInput.addEventListener('input', () => {
      clearTimeout(t);
      t = setTimeout(filter, 120);
    });
    filter();
  }
});


// Google Places + fallback geocode pour adresse (création + édition)
(function () {
  let ready = false;
  const waitMaps = () => new Promise(r => {
    if (ready && window.google?.maps?.places) return r(true);
    (function check(){ (window.google?.maps?.places) ? (ready=true,r(true)) : setTimeout(check,80); })();
  });

  function wire(input, latEl, lngEl) {
    if (!input) return;
    const ac = new google.maps.places.Autocomplete(input, {
      fields: ['geometry','formatted_address','place_id'],
      // componentRestrictions: { country: 'fr' }, // optionnel
      types: ['geocode']
    });
    ac.addListener('place_changed', () => {
      const p = ac.getPlace(); if (!p?.geometry) return;
      const loc = p.geometry.location;
      latEl.value = typeof loc.lat === 'function' ? loc.lat() : loc.lat;
      lngEl.value = typeof loc.lng === 'function' ? loc.lng() : loc.lng;
      if (p.formatted_address) input.value = p.formatted_address;
    });
    input.addEventListener('keydown', e => { if (e.key === 'Enter') e.preventDefault(); });
  }

  async function geocode(addr) {
    const url = `https://maps.googleapis.com/maps/api/geocode/json?address=${encodeURIComponent(addr)}&key=${encodeURIComponent(window.GMAPS_KEY||'')}`;
    const r = await fetch(url); const j = await r.json();
    if (j.status !== 'OK' || !j.results?.length) return null;
    const g = j.results[0];
    return { lat: g.geometry.location.lat, lng: g.geometry.location.lng, formatted: g.formatted_address || addr };
  }

  function ensureCoordsOnSubmit(form, input, latEl, lngEl) {
    form.addEventListener('submit', async (e) => {
      const addr = (input.value||'').trim();
      if (!addr) return; // le required HTML gère

      if (!latEl.value || !lngEl.value) {
        e.preventDefault();
        const g = await geocode(addr);
        if (g) { latEl.value=g.lat; lngEl.value=g.lng; input.value=g.formatted; form.submit(); }
        else   { alert("Adresse introuvable. Sélectionne une suggestion ou précise l'adresse."); }
      }
    });
  }

  // Dépôt : Création
document.addEventListener('show.bs.modal', async (ev) => {
  if (!ev.target.matches('#modalDepotCreate')) return;
  await waitMaps();
  const f   = document.getElementById('formDepotCreate');
  const i   = document.getElementById('createAdresse');
  const lat = document.getElementById('createLat');
  const lng = document.getElementById('createLng');
  if (!f || !i || !lat || !lng) return;
  lat.value = ''; lng.value = '';
  wire(i, lat, lng);
  ensureCoordsOnSubmit(f, i, lat, lng);   // remplit lat/lng si l’utilisateur n’a pas cliqué une suggestion
});

// Dépôt : Édition
document.addEventListener('show.bs.modal', async (ev) => {
  if (!ev.target.matches('#modalDepotEdit')) return;
  await waitMaps();
  const f   = document.getElementById('formDepotEdit');
  const i   = document.getElementById('editAdresse');
  const lat = document.getElementById('editLat');
  const lng = document.getElementById('editLng');
  if (!f || !i || !lat || !lng) return;
  wire(i, lat, lng);
  ensureCoordsOnSubmit(f, i, lat, lng);
});})
