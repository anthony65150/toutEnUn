document.addEventListener('DOMContentLoaded', () => {
  /* ========================
     Helpers Modales
  ======================== */
  function getEmployeModal() {
    const el = document.getElementById('employeModal');
    return (el && window.bootstrap)
      ? bootstrap.Modal.getOrCreateInstance(el, { backdrop:true, keyboard:true })
      : null;
  }
  function getDeleteModal() {
    const el = document.getElementById('confirmDeleteModal');
    return (el && window.bootstrap)
      ? bootstrap.Modal.getOrCreateInstance(el, { backdrop:true, keyboard:true })
      : null;
  }

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
  const emp_agence   = document.getElementById('emp_agence');

  const openAgenceEl = document.getElementById('openAgenceLink');
  const agenceForm   = document.getElementById('agenceForm');
  const API_AGENCES  = '/agences/api.php';

  const filterWrap   = document.getElementById('agenceFilters');
  let selectedAgence = ''; // '' = Tous

  const ACTION_URL   = '/employes/employes_actions.php';
  const searchInput  = document.getElementById('employeSearchInput');

  /* ========================
     Filtres + Recherche
  ======================== */
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
      const agencyId = tr.getAttribute('data-agence-id') || '';
      const matchesAgence =
        selectedAgence === '' ||
        (selectedAgence === '0' && (agencyId === '' || agencyId === '0')) ||
        agencyId === selectedAgence;

      const matchesSearch = (!q || normalize(tr.textContent).includes(q));
      const show = matchesAgence && matchesSearch;

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

  /* ========================
     Agences
  ======================== */
  async function loadAgences(preselect = '') {
    if (!emp_agence || !window.Agences) return;
    await window.Agences.loadIntoSelect(emp_agence, {
      includePlaceholder: true,
      preselect: preselect ? String(preselect) : ''
    });
  }

  openAgenceEl?.addEventListener('click', (e) => {
    e.preventDefault();
    const el = document.getElementById('agenceModal');
    if (!el) return;
    if (el.closest('#employeModal')) document.body.appendChild(el);
    agenceForm?.reset();
    bootstrap.Modal.getOrCreateInstance(el).show();
  });

  async function fetchAgences() {
    try {
      const res = await fetch('/agences/api.php?action=list', { credentials: 'same-origin' });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const data = await res.json();
      return (data && data.ok && Array.isArray(data.items)) ? data.items : [];
    } catch (e) {
      console.error('fetchAgences error', e);
      return [];
    }
  }

  async function buildAgenceFilters() {
    if (!filterWrap) return;
    const agences = await fetchAgences();
    const size = 'btn-md px-4 py-2';
    filterWrap.innerHTML = `
      <button type="button" class="btn btn-primary ${size} filter-agence active" data-agence="">
        Tous
      </button>
      <button type="button" class="btn btn-outline-secondary ${size} filter-agence" data-agence="0">
        Sans agence
      </button>
      ${agences.map(a => `
        <button type="button" class="btn btn-outline-primary ${size} filter-agence" data-agence="${a.id}">
          ${a.nom}
        </button>
      `).join('')}
    `;
  }

  filterWrap?.addEventListener('click', (e) => {
    const btn = e.target.closest('.filter-agence');
    if (!btn) return;
    selectedAgence = btn.getAttribute('data-agence') || '';

    filterWrap.querySelectorAll('.filter-agence').forEach(b => {
      b.classList.remove('active', 'btn-primary');
      if (!b.classList.contains('btn-outline-secondary')) {
        b.classList.add('btn-outline-primary');
      }
    });
    btn.classList.add('active');
    if (btn.classList.contains('btn-outline-primary')) {
      btn.classList.remove('btn-outline-primary');
      btn.classList.add('btn-primary');
    }
    applyFilter();
  });

  // Mini-modale agence
  let creatingAgence = false;
  agenceForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (creatingAgence) return;
    creatingAgence = true;

    const submitBtn = agenceForm.querySelector('button[type="submit"]');
    submitBtn?.setAttribute('disabled','disabled');
    const fd = new FormData(agenceForm);
    fd.append('action','create');

    try {
      const res = await fetch(API_AGENCES, { method:'POST', body: fd, credentials:'same-origin' });
      const text = await res.text();
      const data = JSON.parse(text);
      if (!data.ok || !data.id) {
        alert(data.msg || 'Erreur lors de la création de l’agence');
        return;
      }
      bootstrap.Modal.getOrCreateInstance(document.getElementById('agenceModal')).hide();
      await loadAgences(String(data.id));
      await buildAgenceFilters();
      applyFilter();
    } catch (err) {
      console.error(err);
      alert('Erreur réseau (agences).');
    } finally {
      creatingAgence = false;
      submitBtn?.removeAttribute('disabled');
    }
  });

  /* ========================
     Ouverture Modale Employé
  ======================== */
  document.querySelector('[data-bs-target="#employeModal"]')
    ?.addEventListener('click', async () => {
      if (!form) return;
      title && (title.textContent = 'Ajouter un employé');
      form.reset();
      if (emp_id) emp_id.value = '';
      if (emp_password) emp_password.required = true;
      await loadAgences('');
    });

  tableBody?.addEventListener('click', async (e) => {
    const btn = e.target.closest?.('.edit-btn');
    if (!btn) return;
    const tr = btn.closest('tr');
    if (!tr) return;

    title && (title.textContent = 'Modifier un employé');
    if (emp_id)       emp_id.value       = tr.dataset.id || '';
    if (emp_nom)      emp_nom.value      = tr.dataset.nom || '';
    if (emp_prenom)   emp_prenom.value   = tr.dataset.prenom || '';
    if (emp_email)    emp_email.value    = tr.dataset.email || '';
    if (emp_fonction) emp_fonction.value = (tr.dataset.fonction || '').toLowerCase();
    if (emp_password) {
      emp_password.value = '';
      emp_password.required = false;
    }

    const agenceIdFromRow = tr.getAttribute('data-agence-id') || '';
    await loadAgences(agenceIdFromRow);
    await buildAgenceFilters();

    getEmployeModal()?.show();
  });

  /* ========================
     Suppression
  ======================== */
  // Remplir automatiquement l'id lors de l'ouverture de la modale
  document.getElementById('confirmDeleteModal')
    ?.addEventListener('show.bs.modal', (ev) => {
      const btn = ev.relatedTarget;
      const id  = btn?.getAttribute('data-id') || '';
      const del = document.getElementById('delete_id');
      if (del) del.value = id;
    });

  /* ========================
     Submit création/édition
  ======================== */
  form?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    fd.append('action', emp_id?.value ? 'update' : 'create');

    try {
      const res = await fetch(ACTION_URL, { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json();
      if (!data.success) {
        alert(data.message || 'Erreur.');
        return;
      }

      if (data.rowHtml && tableBody) {
        const existing = tableBody.querySelector(`tr[data-id="${data.id}"]`);
        if (existing) existing.outerHTML = data.rowHtml;
        else tableBody.insertAdjacentHTML('afterbegin', data.rowHtml);
        highlightRow(data.id);
        applyFilter();
      } else {
        location.reload();
      }

      getEmployeModal()?.hide();
    } catch (err) {
      console.error(err);
      alert('Erreur réseau.');
    }
  });

  /* ========================
     Submit suppression
  ======================== */
  deleteForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(deleteForm);
    fd.append('action', 'delete');

    try {
      const res = await fetch(ACTION_URL, { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json();
      if (!data.success) {
        alert(data.message || 'Erreur.');
        return;
      }
      if (tableBody && data.id) {
        tableBody.querySelector(`tr[data-id="${data.id}"]`)?.remove();
      }
      getDeleteModal()?.hide();
      applyFilter();
    } catch (err) {
      console.error(err);
      alert('Erreur réseau.');
    }
  });

  /* ========================
     Utilitaires
  ======================== */
  function highlightRow(id) {
    if (!tableBody || !id) return;
    const row = tableBody.querySelector(`tr[data-id="${id}"]`);
    if (!row) return;
    row.classList.add('table-warning');
    setTimeout(() => row.classList.remove('table-warning'), 3000);
  }

  (async () => {
    await buildAgenceFilters();
    applyFilter();
  })();
});
