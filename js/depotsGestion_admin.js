document.addEventListener('DOMContentLoaded', () => {
  const createForm = document.getElementById('formDepotCreate');
  const editForm   = document.getElementById('formDepotEdit');
  const deleteForm = document.getElementById('formDepotDelete');

  const modalEditEl = document.getElementById('modalDepotEdit');
  const modalDelEl  = document.getElementById('modalDepotDelete');
  const modalEdit   = modalEditEl ? new bootstrap.Modal(modalEditEl) : null;
  const modalDel    = modalDelEl ? new bootstrap.Modal(modalDelEl) : null;

  // Utilitaire fetch JSON avec gestion erreurs
  const postForm = async (fd) => {
    const res = await fetch('depots_api.php', {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    if (!res.ok) {
      const txt = await res.text().catch(() => '');
      throw new Error(`HTTP ${res.status} – ${txt.substring(0, 200)}`);
    }
    let j;
    try { j = await res.json(); }
    catch (e) {
      const txt = await res.text().catch(()=> '');
      throw new Error(`Réponse non-JSON: ${txt.substring(0, 200)}`);
    }
    if (!j.ok) throw new Error(j.error || 'Erreur inconnue');
    return j;
  };

  // CREATE
  createForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(createForm);
    fd.append('action', 'create');
    try {
      await postForm(fd);
      location.reload();
    } catch (err) {
      console.error(err);
      alert(err.message || 'Erreur création');
    }
  });

  // Open Edit
  document.getElementById('depotsTbody')?.addEventListener('click', (e) => {
    const btn = e.target.closest('.edit-depot-btn');
    if (!btn) return;
    document.getElementById('editDepotId').value = btn.dataset.id || '';
    document.getElementById('editNom').value     = btn.dataset.nom || '';
    document.getElementById('editResp').value    =
      (btn.dataset.resp && btn.dataset.resp !== '0') ? btn.dataset.resp : '';
    modalEdit?.show();
  });

  // UPDATE
  editForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(editForm);
    fd.append('action', 'update');
    try {
      await postForm(fd);
      location.reload();
    } catch (err) {
      console.error(err);
      alert(err.message || 'Erreur mise à jour');
    }
  });

  // Open Delete
  document.getElementById('depotsTbody')?.addEventListener('click', (e) => {
    const btn = e.target.closest('.delete-depot-btn');
    if (!btn) return;
    document.getElementById('deleteDepotId').value = btn.dataset.id || '';
    modalDel?.show();
  });

  // DELETE
  deleteForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(deleteForm);
    fd.append('action', 'delete');
    try {
      await postForm(fd);
      location.reload();
    } catch (err) {
      console.error(err);
      alert(err.message || 'Erreur suppression');
    }
  });

  // --- RECHERCHE DÉPÔTS ---
  const searchInput = document.getElementById('depotSearchInput');
  const tbody = document.getElementById('depotsTbody');

  if (searchInput && tbody) {
    // crée la ligne "aucun résultat" si absente
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
        .replace(/[\u0300-\u036f]/g, '') // retire accents
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
      t = setTimeout(filter, 120); // petit debounce
    });

    // init
    filter();
  }
});
