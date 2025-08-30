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

// ---------- AGENCES : éléments ----------
const emp_agence    = document.getElementById('emp_agence');
const openAgenceEl  = document.getElementById('openAgenceLink'); // <-- nouveau (lien)
const agenceModalEl = document.getElementById('agenceModal');
const agenceModal   = agenceModalEl ? new bootstrap.Modal(agenceModalEl) : null;
const agenceForm    = document.getElementById('agenceForm');
const API_AGENCES   = '/agences/api.php';
const filterWrap = document.getElementById('agenceFilters');
let selectedAgence = ''; // '' = Tous



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
    const agencyId = tr.getAttribute('data-agence-id') || '';
    const matchesAgence =
      selectedAgence === ''                       // Tous
      || (selectedAgence === '0' && (agencyId === '' || agencyId === '0'))  // Sans agence
      || agencyId === selectedAgence;            // Agence précise

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
  // ---------------------------------------

  // ---------- AGENCES : helpers ----------
  async function loadAgences(preselect = '') {
    if (!emp_agence || !window.Agences) return;
    await window.Agences.loadIntoSelect(emp_agence, {
      includePlaceholder: true,
      preselect: preselect ? String(preselect) : ''
    });
  }
  // ---- OUVERTURE de la mini-modale "Ajouter une agence" ----
openAgenceEl?.addEventListener('click', (e) => {
  e.preventDefault();

  const el = document.getElementById('agenceModal');
  if (!el) return;

  // Si par erreur la mini-modale est imbriquée dans la grande, on la déplace sous <body>
  if (el.closest('#employeModal')) {
    document.body.appendChild(el);
  }

  agenceForm?.reset();

  // (Re)crée ou récupère l'instance au clic pour plus de fiabilité
  const inst = bootstrap.Modal.getOrCreateInstance(el, { backdrop: true, keyboard: true });
  inst.show();
});

// Récupère la liste des agences via l'API
async function fetchAgences() {
  try {
    const res = await fetch('/agences/api.php?action=list', { credentials: 'same-origin' });
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

  const size = 'btn-md px-4 py-2'; // <- la taille des boutons (gros)
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


// Gestion des clics sur la barre de filtres
filterWrap?.addEventListener('click', (e) => {
  const btn = e.target.closest('.filter-agence');
  if (!btn) return;

  selectedAgence = btn.getAttribute('data-agence') || '';

  // État visuel
  filterWrap.querySelectorAll('.filter-agence').forEach(b => {
    b.classList.remove('active', 'btn-primary');
    if (b.classList.contains('btn-outline-secondary')) return; // laisse le style "Sans agence"
    b.classList.add('btn-outline-primary');
  });
  btn.classList.add('active');
  if (btn.classList.contains('btn-outline-primary')) {
    btn.classList.remove('btn-outline-primary');
    btn.classList.add('btn-primary');
  }

  applyFilter();
});



  // Ouvrir modale en création
  document.querySelector('[data-bs-target="#employeModal"]')?.addEventListener('click', async () => {
    if (!form) return;
    title && (title.textContent = 'Ajouter un employé');
    form.reset();
    if (emp_id) emp_id.value = '';
    if (emp_password) emp_password.required = true; // mdp requis en création

    // AGENCES: charger la liste (pas de présélection)
    await loadAgences('');
  });

  // Édition (délégation)
  tableBody?.addEventListener('click', async (e) => {
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

    // AGENCES: récupérer l'id stocké en data-agence-id sur la <tr> (ajoute l'attribut côté PHP)
    const agenceIdFromRow = tr.getAttribute('data-agence-id') || '';

    // On charge, puis on présélectionne la valeur existante (si présente)
    await loadAgences(agenceIdFromRow);
    await buildAgenceFilters();   // met à jour les chips avec la nouvelle agence

    employeModal.show();
  });

// ---------- AGENCES : mini-modale ----------
let creatingAgence = false; // <-- garde-fou global

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
    const data = await res.json();

    if (!data || !data.ok || !data.id) {
      alert((data && data.msg) || 'Erreur lors de la création de l’agence');
      return;
    }

    // Que l’agence soit nouvelle ou déjà existante -> on sélectionne et on ferme
    bootstrap.Modal.getOrCreateInstance(document.getElementById('agenceModal')).hide();
    await loadAgences(String(data.id)); // recharge la liste et présélectionne
  } catch (err) {
    console.error(err);
    alert('Erreur réseau (agences).');
  } finally {
    creatingAgence = false;
    submitBtn?.removeAttribute('disabled');
  }
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

// Construire les filtres puis appliquer le filtrage initial
buildAgenceFilters().then(applyFilter);
})