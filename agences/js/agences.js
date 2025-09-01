// /agences/js/agences.js
(() => {
  const API = '/agences/api.php';
  const $ = (sel, ctx=document) => ctx.querySelector(sel);
  const $$ = (sel, ctx=document) => Array.from(ctx.querySelectorAll(sel));

  // ======= Utilitaire réutilisable pour tes autres pages =======
  // Appel: Agences.loadIntoSelect(selectEl, { preselect: 3, includePlaceholder:true })
  window.Agences = {
    async list(q='') {
      try {
        const res = await fetch(`${API}?action=list&q=${encodeURIComponent(q)}`, { credentials:'same-origin' });
        const data = await res.json();
        return data.ok ? (data.items || []) : [];
      } catch (e) {
        console.error('Agences.list error', e);
        return [];
      }
    },
    async loadIntoSelect(selectEl, opts={}) {
      const { preselect = '', includePlaceholder = true } = opts;
      const items = await this.list();
      // reset
      selectEl.innerHTML = '';
      if (includePlaceholder) {
        const p = document.createElement('option');
        p.value = '';
        p.textContent = '-- Sélectionner une agence --';
        selectEl.appendChild(p);
      }
      items.forEach(it => {
        const o = document.createElement('option');
        o.value = it.id;
        o.textContent = it.nom;
        selectEl.appendChild(o);
      });
      if (preselect !== '') selectEl.value = String(preselect);
    }
  };

  // ======= Code de la page /agences/agences.php =======
  const tableBody = $('#agencesTable tbody');
  if (!tableBody) return; // si chargé ailleurs, on s'arrête pour la partie page

  const searchInput   = $('#agenceSearch');
  const editModalEl   = $('#agenceEditModal');
  const editModal     = new bootstrap.Modal(editModalEl);
  const form          = $('#agenceEditForm');
  const idInput       = $('#agenceId');
  const nomInput      = $('#agenceNom');
  const adresseInput  = $('#agenceAdresse');
  const btnNew        = $('#btnNewAgence');

  const submitBtn = () => form.querySelector('button[type="submit"]');

  function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  }

  function rowHtml(it, idx){
    return `
      <tr data-id="${it.id}">
        <td>${idx+1}</td>
        <td class="ag-nom">${escapeHtml(it.nom)}</td>
        <td class="ag-adresse">${escapeHtml(it.adresse ?? '')}</td>
        <td>
          <button class="btn btn-sm btn-outline-primary me-2 btn-edit">Modifier</button>
          <button class="btn btn-sm btn-outline-danger btn-del">Supprimer</button>
        </td>
      </tr>
    `;
  }

  async function reload(q='') {
    const items = await Agences.list(q);
    tableBody.innerHTML = items.map((it, i) => rowHtml(it, i)).join('');
  }

  function highlightRowById(id){
    const tr = tableBody.querySelector(`tr[data-id="${id}"]`);
    if (!tr) return;
    tr.classList.add('table-warning');
    setTimeout(()=>tr.classList.remove('table-warning'), 1200);
  }

  btnNew?.addEventListener('click', () => {
    form.reset();
    idInput.value = '';
    editModal.show();
    setTimeout(()=>nomInput.focus(), 150);
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    // simple validation
    if (!nomInput.value.trim()) {
      nomInput.focus();
      return;
    }

    const fd = new FormData(form);
    const isUpdate = idInput.value !== '';
    fd.append('action', isUpdate ? 'update' : 'create');

    const btn = submitBtn();
    const originalText = btn?.textContent;
    btn && (btn.disabled = true, btn.textContent = 'Enregistrement...');

    try {
      const res = await fetch(API, { method:'POST', body:fd, credentials:'same-origin' });
      const data = await res.json();

      if (!data.ok) {
        alert(data.msg || 'Erreur');
        return;
      }

      editModal.hide();
      await reload(searchInput.value.trim());

      // feedback visuel
      if (isUpdate) {
        highlightRowById(idInput.value);
      } else {
        // si l’API renvoie existing=true on highlight quand même
        highlightRowById(data.id);
        if (data.existing === true) {
          // optionnel: prévenir l’utilisateur
          // alert('Cette agence existait déjà, je l’ai sélectionnée.');
        }
      }
    } catch (err) {
      console.error(err);
      alert('Erreur réseau, réessaie.');
    } finally {
      btn && (btn.disabled = false, btn.textContent = originalText);
    }
  });

  tableBody.addEventListener('click', async (e) => {
    const btnEdit = e.target.closest('.btn-edit');
    const btnDel  = e.target.closest('.btn-del');
    if (!btnEdit && !btnDel) return;

    const tr = e.target.closest('tr');
    if (!tr) return;
    const id = tr.getAttribute('data-id');

    if (btnEdit) {
      // préremplir
      idInput.value = id;
      nomInput.value = tr.querySelector('.ag-nom')?.textContent.trim() || '';
      adresseInput.value = tr.querySelector('.ag-adresse')?.textContent.trim() || '';
      editModal.show();
      setTimeout(()=>nomInput.focus(), 120);
      return;
    }

    if (btnDel) {
      if (!confirm('Supprimer cette agence ?')) return;

      const fd = new FormData();
      fd.append('csrf_token', form.querySelector('[name="csrf_token"]').value);
      fd.append('action','delete');
      fd.append('id', id);

      try {
        const res = await fetch(API, { method:'POST', body:fd, credentials:'same-origin' });
        const data = await res.json();
        if (!data.ok) { alert(data.msg || 'Erreur'); return; }
        await reload(searchInput.value.trim());
      } catch (err) {
        console.error(err);
        alert('Erreur réseau, réessaie.');
      }
    }
  });

  let t;
  searchInput?.addEventListener('input', () => {
    clearTimeout(t);
    t = setTimeout(()=>reload(searchInput.value.trim()), 250);
  });

  // init
  reload();
})();
