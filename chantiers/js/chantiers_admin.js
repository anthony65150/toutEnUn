// /js/chantiers_admin.js
document.addEventListener('DOMContentLoaded', () => {
  /* =========================
     Toast utilitaire
     ========================= */
  window.showChantierToast = function(message = 'Chantier enregistré avec succès') {
    const toastEl = document.getElementById('chantierToast');
    const toastMsg = document.getElementById('chantierToastMsg');
    if (!toastEl || !toastMsg) return alert(message);
    toastMsg.textContent = message;
    new bootstrap.Toast(toastEl).show();
  };

  /* =========================
     Recherche avec "aucun résultat"
     ========================= */
  const input = document.getElementById('chantierSearchInput');
  const tbody = document.getElementById('chantiersTableBody');

  if (input && tbody) {
    let noRow = document.getElementById('noResultsChantier');
    if (!noRow) {
      noRow = document.createElement('tr');
      noRow.id = 'noResultsChantier';
      noRow.className = 'd-none';
      noRow.innerHTML = `<td colspan="5" class="text-muted text-center py-4">Aucun chantier trouvé</td>`;
      tbody.appendChild(noRow);
    }

    const normalize = (s) => (s || '').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
    const getRows = () => Array.from(tbody.querySelectorAll('tr')).filter(tr => tr !== noRow);

    const filter = () => {
      const q = normalize(input.value);
      let visible = 0;
      getRows().forEach(tr => {
        const show = !q || normalize(tr.textContent).includes(q);
        tr.style.display = show ? '' : 'none';
        if (show) visible++;
      });
      noRow.classList.toggle('d-none', visible !== 0);
    };

    let t;
    input.addEventListener('input', () => {
      clearTimeout(t);
      t = setTimeout(filter, 120);
    });
    filter();
  }

  /* =========================
     Remplissage MODALE EDIT au moment de l'ouverture
     ========================= */
  const editModalEl = document.getElementById('chantierEditModal');
  if (editModalEl) {
    editModalEl.addEventListener('show.bs.modal', (ev) => {
      const btn = ev.relatedTarget; // bouton .edit-btn qui a déclenché la modale
      if (!btn) return;

      const form = document.getElementById('chantierEditForm');
      if (!form) return;

      const id    = btn.getAttribute('data-id') || '';
      const nom   = btn.getAttribute('data-nom') || '';
      const desc  = btn.getAttribute('data-description') || '';
      const debut = btn.getAttribute('data-debut') || '';
      const fin   = btn.getAttribute('data-fin') || '';

      // Multi-chefs: data-chef-ids="1,5,8" (séparés par virgule)
      // Fallback mono-chef: data-chef="3"
      const chefIdsStr = btn.getAttribute('data-chef-ids') || '';
      const singleChef = btn.getAttribute('data-chef') || '';
      const targetIds = chefIdsStr
        ? chefIdsStr.split(',').map(s => s.trim()).filter(Boolean)
        : (singleChef ? [String(singleChef)] : []);

      // Remplir champs
      form.querySelector('#chantierIdEdit').value    = id;
      form.querySelector('#chantierNomEdit').value   = nom;
      form.querySelector('#chantierDescEdit').value  = desc;
      form.querySelector('#chantierDebutEdit').value = debut;
      form.querySelector('#chantierFinEdit').value   = fin;

      // Sélection chefs
      const select = form.querySelector('#chefChantierEdit');
      if (select) {
        const isMultiple = !!select.multiple;
        Array.from(select.options).forEach(opt => {
          opt.selected = targetIds.includes(opt.value);
        });
        if (!isMultiple && targetIds.length) {
          select.value = targetIds[0];
        }
      }
    });
  }

  /* =========================
     Délégation: Delete / Create
     ========================= */
  document.addEventListener('click', (ev) => {
    const delBtn   = ev.target.closest('.delete-btn');
    const createBtn = ev.target.closest('[data-bs-target="#chantierModal"]');

    // Préparer la suppression
    if (delBtn) {
      const id = delBtn.dataset.id || '';
      const delForm = document.getElementById('deleteForm');
      if (delForm) delForm.querySelector('#deleteId').value = id;
      return;
    }

    // Ouverture modale création: reset propre
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
      }
    }
  });
});
