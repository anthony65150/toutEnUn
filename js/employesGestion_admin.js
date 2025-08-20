document.addEventListener('DOMContentLoaded', () => {
  const employeModal = new bootstrap.Modal(document.getElementById('employeModal'));
  const deleteModal  = new bootstrap.Modal(document.getElementById('confirmDeleteModal'));

  const tableBody = document.getElementById('employesTableBody');
  const form = document.getElementById('employeForm');
  const deleteForm = document.getElementById('deleteForm');
  const title = document.getElementById('employeModalTitle');

  const emp_id = document.getElementById('emp_id');
  const emp_prenom = document.getElementById('emp_prenom');
  const emp_nom = document.getElementById('emp_nom');
  const emp_email = document.getElementById('emp_email');
  const emp_fonction = document.getElementById('emp_fonction');
  const emp_password = document.getElementById('emp_password');

  const ACTION_URL = 'employes_actions.php'; // relatif = robuste même en sous-dossier

  // Ouvrir modale en création
  document.querySelector('[data-bs-target="#employeModal"]').addEventListener('click', () => {
    title.textContent = 'Ajouter un employé';
    form.reset();
    emp_id.value = '';
    emp_password.required = true;   // mdp requis uniquement en création
  });

  // Édition (délégation)
  tableBody.addEventListener('click', (e) => {
    const btn = e.target.closest('.edit-btn');
    if (!btn) return;

    const tr = btn.closest('tr');
    title.textContent = 'Modifier un employé';
    emp_id.value = tr.dataset.id;
    emp_nom.value = tr.dataset.nom || '';
    emp_prenom.value = tr.dataset.prenom || '';
    emp_email.value = tr.dataset.email || '';
    emp_fonction.value = (tr.dataset.fonction || '').toLowerCase();
    emp_password.value = '';
    emp_password.required = false;  // pas requis en modification

    employeModal.show();
  });

  // Suppression (délégation)
  tableBody.addEventListener('click', (e) => {
    const btn = e.target.closest('.delete-btn');
    if (!btn) return;
    const tr = btn.closest('tr');
    document.getElementById('delete_id').value = tr.dataset.id;
    deleteModal.show();
  });

  // Submit création/édition — avec debug robuste
  form.addEventListener('submit', async (e) => {
    e.stopImmediatePropagation();
    e.preventDefault();
    const fd = new FormData(form);
    fd.append('action', emp_id.value ? 'update' : 'create');

    try {
      const res = await fetch(ACTION_URL, { method: 'POST', body: fd });
      const text = await res.text();
      let data;
      try {
        data = JSON.parse(text);
      } catch {
        console.error('Réponse non-JSON du serveur:', text);
        alert("Réponse serveur invalide (voir console).");
        return;
      }

      if (!data.success) {
        console.error('Erreur backend:', data);
        alert(data.message || 'Erreur (voir console).');
        return;
      }

      if (data.rowHtml) {
        const existing = tableBody.querySelector(`tr[data-id="${data.id}"]`);
        if (existing) {
          existing.outerHTML = data.rowHtml;
        } else {
          tableBody.insertAdjacentHTML('afterbegin', data.rowHtml);
        }
        highlightRow(data.id);
      } else {
        location.reload();
      }
      employeModal.hide();
    } catch (err) {
      console.error('Erreur réseau/fetch:', err);
      alert("Impossible de contacter le serveur (voir console).");
    }
  }, { capture: true });

  // Submit suppression — avec debug
  deleteForm.addEventListener('submit', async (e) => {
    e.stopImmediatePropagation();
    e.preventDefault();
    const fd = new FormData(deleteForm);
    fd.append('action', 'delete');

    try {
      const res = await fetch('employes_actions.php', { method: 'POST', body: fd });

      const text = await res.text();
      let data;
      try {
        data = JSON.parse(text);
      } catch {
        console.error('Réponse non-JSON du serveur:', text);
        alert("Réponse serveur invalide (voir console).");
        return;
      }

      if (!data.success) {
        console.error('Erreur backend:', data);
        alert(data.message || 'Erreur (voir console).');
        return;
      }
      const tr = tableBody.querySelector(`tr[data-id="${data.id}"]`);
      if (tr) tr.remove();
      deleteModal.hide();
    } catch (err) {
      console.error('Erreur réseau/fetch:', err);
      alert("Impossible de contacter le serveur (voir console).");
    }
  }, { capture: true });

  function highlightRow(id) {
    const row = tableBody.querySelector(`tr[data-id="${id}"]`);
    if (!row) return;
    row.classList.add('table-warning');
    setTimeout(() => row.classList.remove('table-warning'), 3000);
  }
});
