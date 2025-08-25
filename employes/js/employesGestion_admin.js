document.addEventListener('DOMContentLoaded', () => {
  // --- Elements principaux (avec garde-fous)
  const employeModalEl = document.getElementById('employeModal');
  const deleteModalEl  = document.getElementById('confirmDeleteModal');

  if (!employeModalEl || !deleteModalEl) return; // page non concernée

  const employeModal = new bootstrap.Modal(employeModalEl);
  const deleteModal  = new bootstrap.Modal(deleteModalEl);

  const tableBody   = document.getElementById('employesTableBody');
  const form        = document.getElementById('employeForm');
  const deleteForm  = document.getElementById('deleteForm');
  const title       = document.getElementById('employeModalTitle');

  const emp_id       = document.getElementById('emp_id');
  const emp_prenom   = document.getElementById('emp_prenom');
  const emp_nom      = document.getElementById('emp_nom');
  const emp_email    = document.getElementById('emp_email');
  const emp_fonction = document.getElementById('emp_fonction');
  const emp_password = document.getElementById('emp_password');

  // IMPORTANT: URL absolue pour éviter les problèmes de chemin
  const ACTION_URL = '/employes/employes_actions.php';

  // ---------- RECHERCHE EMPLOYÉS ----------
  const searchInput = document.getElementById('employeSearchInput');

  let noRow = document.getElementById('noResultsEmploye');
  if (!noRow && tableBody) {
    noRow = document.createElement('tr');
    noRow.id = 'noResultsEmploye';
    noRow.className = 'd-none';
    noRow.innerHTML = `<td colspan="5" class="text-muted text-center py-4">Aucun employé trouvé</td>`;
    tableBody.appendChild(noRow);
  }

  const rows = () =>
    Array.from(tableBody?.querySelectorAll('tr') || []).filter(tr => tr !== noRow);

  const normalize = (s) =>
    (s || '')
      .toString()
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .trim();

  const applyFilter = () => {
    if (!searchInput || !tableBody) return;
    const q = normalize(searchInput.value);
    let visible = 0;
    rows().forEach(tr => {
      const show = !q || normalize(tr.textContent).includes(q);
      tr.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    if (noRow) noRow.classList.toggle('d-none', visible !== 0);
  };

  let debounce;
  searchInput?.addEventListener('input', () => {
    clearTimeout(debounce);
    debounce = setTimeout(applyFilter, 120);
  });
  // ---------------------------------------

  // Ouvrir modale en création
  document.querySelector('[data-bs-target="#employeModal"]')?.addEventListener('click', () => {
    if (!form) return;
    title && (title.textContent = 'Ajouter un employé');
    form.reset();
    if (emp_id) emp_id.value = '';
    if (emp_password) emp_password.required = true; // mdp requis en création
  });

  // Édition (délégation)
  tableBody?.addEventListener('click', (e) => {
    const btn = e.target.closest?.('.edit-btn');
    if (!btn) return;

    const tr = btn.closest('tr');
    if (!tr) return;

    if (title) title.textContent = 'Modifier un employé';
    if (emp_id)       emp_id.value       = tr.dataset.id || '';
    if (emp_nom)      emp_nom.value      = tr.dataset.nom || '';
    if (emp_prenom)   emp_prenom.value   = tr.dataset.prenom || '';
    if (emp_email)    emp_email.value    = tr.dataset.email || '';
    if (emp_fonction) emp_fonction.value = (tr.dataset.fonction || '').toLowerCase();
    if (emp_password) {
      emp_password.value = '';
      emp_password.required = false; // pas requis en modification
    }

    employeModal.show();
  });

  // Suppression (délégation)
  tableBody?.addEventListener('click', (e) => {
    const btn = e.target.closest?.('.delete-btn');
    if (!btn) return;

    const tr = btn.closest('tr');
    if (!tr) return;

    const delId = document.getElementById('delete_id');
    if (delId) delId.value = tr.dataset.id || '';

    deleteModal.show();
  });

  // Submit création/édition — avec debug robuste
  form?.addEventListener(
    'submit',
    async (e) => {
      e.stopImmediatePropagation();
      e.preventDefault();
      const fd = new FormData(form);
      fd.append('action', emp_id?.value ? 'update' : 'create');

      try {
        const res = await fetch(ACTION_URL, { method: 'POST', body: fd });
        const text = await res.text();

        let data;
        try {
          data = JSON.parse(text);
        } catch {
          console.error('Réponse non-JSON du serveur:', text);
          alert('Réponse serveur invalide (voir console).');
          return;
        }

        if (!data.success) {
          console.error('Erreur backend:', data);
          alert(data.message || 'Erreur (voir console).');
          return;
        }

        if (data.rowHtml && tableBody) {
          const existing = tableBody.querySelector(`tr[data-id="${data.id}"]`);
          if (existing) {
            existing.outerHTML = data.rowHtml;
          } else {
            tableBody.insertAdjacentHTML('afterbegin', data.rowHtml);
          }
          highlightRow(data.id);
          applyFilter(); // réapplique le filtre courant
        } else {
          // fallback si le backend ne renvoie pas rowHtml
          location.reload();
        }

        employeModal.hide();
      } catch (err) {
        console.error('Erreur réseau/fetch:', err);
        alert('Impossible de contacter le serveur (voir console).');
      }
    },
    { capture: true }
  );

  // Submit suppression — avec debug
  deleteForm?.addEventListener(
    'submit',
    async (e) => {
      e.stopImmediatePropagation();
      e.preventDefault();
      const fd = new FormData(deleteForm);
      fd.append('action', 'delete');

      try {
        const res = await fetch(ACTION_URL, { method: 'POST', body: fd });
        const text = await res.text();

        let data;
        try {
          data = JSON.parse(text);
        } catch {
          console.error('Réponse non-JSON du serveur:', text);
          alert('Réponse serveur invalide (voir console).');
          return;
        }

        if (!data.success) {
          console.error('Erreur backend:', data);
          alert(data.message || 'Erreur (voir console).');
          return;
        }

        if (tableBody && data.id) {
          const tr = tableBody.querySelector(`tr[data-id="${data.id}"]`);
          if (tr) tr.remove();
        }

        deleteModal.hide();
        applyFilter(); // réapplique le filtre courant
      } catch (err) {
        console.error('Erreur réseau/fetch:', err);
        alert('Impossible de contacter le serveur (voir console).');
      }
    },
    { capture: true }
  );

  function highlightRow(id) {
    if (!tableBody || !id) return;
    const row = tableBody.querySelector(`tr[data-id="${id}"]`);
    if (!row) return;
    row.classList.add('table-warning');
    setTimeout(() => row.classList.remove('table-warning'), 3000);
  }

  // Premier filtrage au chargement (si champ pré-rempli)
  applyFilter();
});
