document.addEventListener('DOMContentLoaded', () => {
  const createForm = document.getElementById('formDepotCreate');
  const editForm   = document.getElementById('formDepotEdit');
  const deleteForm = document.getElementById('formDepotDelete');
  const modalEdit  = new bootstrap.Modal(document.getElementById('modalDepotEdit'));
  const modalDel   = new bootstrap.Modal(document.getElementById('modalDepotDelete'));

  // Create
  createForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(createForm);
    fd.append('action', 'create');
    const res = await fetch('depots_api.php', { method:'POST', body: fd });
    const j = await res.json();
    if (j.ok) location.reload(); else alert(j.error || 'Erreur');
  });

  // Open Edit
  document.getElementById('depotsTbody')?.addEventListener('click', (e) => {
    const btn = e.target.closest('.edit-depot-btn');
    if (!btn) return;
    document.getElementById('editDepotId').value = btn.dataset.id;
    document.getElementById('editNom').value = btn.dataset.nom || '';
    document.getElementById('editResp').value = btn.dataset.resp || '';
    modalEdit.show();
  });

  // Update
  editForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(editForm);
    fd.append('action', 'update');
    const res = await fetch('depots_api.php', { method:'POST', body: fd });
    const j = await res.json();
    if (j.ok) location.reload(); else alert(j.error || 'Erreur');
  });

  // Open Delete
  document.getElementById('depotsTbody')?.addEventListener('click', (e) => {
    const btn = e.target.closest('.delete-depot-btn');
    if (!btn) return;
    document.getElementById('deleteDepotId').value = btn.dataset.id;
    modalDel.show();
  });

  // Delete
  deleteForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(deleteForm);
    fd.append('action', 'delete');
    const res = await fetch('depots_api.php', { method:'POST', body: fd });
    const j = await res.json();
    if (j.ok) location.reload(); else alert(j.error || 'Erreur');
  });
});
