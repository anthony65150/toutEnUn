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

      // Essayer de lire le JSON même si !res.ok pour récupérer j.error
      let payload = null, fallbackText = '';
      try { payload = await res.json(); }
      catch { try { fallbackText = await res.text(); } catch { /* ignore */ } }

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
    const base = location.pathname.replace(/[\?#].*$/, ''); // /depots/depots_admin.php
    const usp = new URLSearchParams(paramsObj);
    location.assign(`${base}?${usp.toString()}`);
  };

  // ===== CREATE =====
  createForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(createForm);
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

    modalEdit?.show();
  });

  // ===== UPDATE =====
  editForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(editForm);
    // on force les champs clés pour être certain qu'ils partent
    fd.set('action', 'update');
    fd.set('id', document.getElementById('editDepotId')?.value || '');
    fd.set('nom', document.getElementById('editNom')?.value || '');
    fd.set('responsable_id', document.getElementById('editResp')?.value ?? '');

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
    // Si jamais ton input n'avait pas name="id", décommente :
    // fd.set('id', document.getElementById('deleteDepotId')?.value || '');

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
      noRow.innerHTML = `<td colspan="4" class="text-muted text-center py-4">Aucun dépôt trouvé</td>`;
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
