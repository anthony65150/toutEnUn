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
      const txt = await res.text().catch(()=>'');
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
});
