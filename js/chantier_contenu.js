// /js/chantier_contenu.js
document.addEventListener('DOMContentLoaded', () => {
  /* =========================
     Utilitaires UI (Toast)
     ========================= */
  const toastEl = document.getElementById('toastMessage');
  let toast;
  if (toastEl) {
    toast = new bootstrap.Toast(toastEl);
  }
  function showToast(message, type = 'primary') {
    if (!toastEl) return alert(message);
    toastEl.classList.remove('text-bg-primary', 'text-bg-success', 'text-bg-danger', 'text-bg-warning', 'text-bg-info');
    toastEl.classList.add(`text-bg-${type}`);
    toastEl.querySelector('.toast-body').textContent = message;
    toast.show();
  }

  /* =========================
     Filtres + Recherche
     ========================= */
  const searchInput = document.getElementById('searchInput');
  const tableBody   = document.getElementById('stockTableBody');
  const rows        = Array.from(tableBody?.querySelectorAll('tr') || []);
  const catSlide    = document.getElementById('categoriesSlide');
  const subSlide    = document.getElementById('subCategoriesSlide');

  let currentCat = '';
  let currentSub = '';

  function applyFilters() {
    const q = (searchInput?.value || '').toLowerCase().trim();
    rows.forEach(tr => {
      const nameEl = tr.querySelector('.article-name');
      const rowCat = (tr.getAttribute('data-cat') || '').toLowerCase();
      const rowSub = (tr.getAttribute('data-subcat') || '').toLowerCase();

      const matchSearch = !q || (nameEl && nameEl.textContent.toLowerCase().includes(q));
      const matchCat    = !currentCat || rowCat === currentCat.toLowerCase();
      const matchSub    = !currentSub || rowSub === currentSub.toLowerCase();

      tr.style.display = (matchSearch && matchCat && matchSub) ? '' : 'none';
    });
  }

  function rebuildSubCats() {
    subSlide.innerHTML = '';
    if (!currentCat) return;

    // Sous-cats à partir des lignes de la catégorie courante
    const subs = new Set();
    rows.forEach(tr => {
      const rowCat = (tr.getAttribute('data-cat') || '').toLowerCase();
      if (rowCat === currentCat.toLowerCase()) {
        const s = (tr.getAttribute('data-subcat') || '').trim();
        if (s) subs.add(s);
      }
    });

    if (subs.size === 0) return;

    // Bouton "Toutes"
    const btnAll = document.createElement('button');
    btnAll.className = 'btn btn-outline-secondary';
    btnAll.textContent = 'Toutes';
    btnAll.addEventListener('click', () => { currentSub = ''; applyFilters(); });
    subSlide.appendChild(btnAll);

    // Boutons sous-catégories
    [...subs].sort((a,b)=>a.localeCompare(b)).forEach(s => {
      const btn = document.createElement('button');
      btn.className = 'btn btn-outline-secondary';
      btn.textContent = s.charAt(0).toUpperCase() + s.slice(1);
      btn.addEventListener('click', () => { currentSub = s.toLowerCase(); applyFilters(); });
      subSlide.appendChild(btn);
    });
  }

  if (searchInput) {
    searchInput.addEventListener('input', applyFilters);
  }
  if (catSlide) {
    catSlide.querySelectorAll('button').forEach(btn => {
      btn.addEventListener('click', () => {
        currentCat = (btn.getAttribute('data-cat') || '').toLowerCase();
        currentSub = '';
        rebuildSubCats();
        applyFilters();
      });
    });
  }
  applyFilters();

  /* =========================
     Modale de transfert
     ========================= */
  const modalEl = document.getElementById('transferModal');
  const transferModal = modalEl ? new bootstrap.Modal(modalEl) : null;

  const transferForm      = document.getElementById('transferForm');
  const inputArticleId    = document.getElementById('articleId');
  const inputSourceChId   = document.getElementById('sourceChantierId');
  const selectDestination = document.getElementById('destinationSelect');
  const inputQuantity     = document.getElementById('quantity');

  // Ouvrir la modale et injecter les données du bouton
  document.querySelectorAll('.transfer-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const articleId   = btn.getAttribute('data-stock-id');
      const articleNom  = btn.getAttribute('data-stock-nom') || 'Article';
      const sourceType  = btn.getAttribute('data-source-type') || 'chantier';
      const sourceId    = btn.getAttribute('data-source-id');

      if (!inputArticleId || !inputSourceChId) return;

      inputArticleId.value  = articleId || '';
      inputSourceChId.value = sourceId || '';

      // Titre dynamique (optionnel)
      const titleEl = modalEl?.querySelector('#transferModalLabel');
      if (titleEl) titleEl.textContent = `Transférer : ${articleNom}`;

      // Reset des champs
      if (selectDestination) selectDestination.value = '';
      if (inputQuantity) inputQuantity.value = '';

      // Ouvre la modale
      transferModal && transferModal.show();
    });
  });

  /* =========================
     Soumission du transfert
     ========================= */
  const ACTION_URL = './transferStock_chef.php'; // 🔁 Change ici si tu utilises un autre endpoint (ex: transferStock.php)

  if (transferForm) {
    transferForm.addEventListener('submit', async (e) => {
      e.preventDefault();

      // Valider inputs
      const articleId = inputArticleId?.value;
      const sourceCh  = inputSourceChId?.value;
      const destRaw   = selectDestination?.value || '';
      const qty       = parseInt(inputQuantity?.value || '0', 10);

      if (!articleId || !sourceCh || !destRaw || !qty || qty < 1) {
        showToast('Veuillez remplir tous les champs.', 'warning');
        return;
      }

      // Parse destination "depot_3" / "chantier_5"
      let destination_type = '';
      let destination_id   = 0;
      const m = destRaw.match(/^(depot|chantier)_(\d+)$/);
      if (m) {
        destination_type = m[1];
        destination_id   = parseInt(m[2], 10);
      } else {
        showToast('Destination invalide.', 'danger');
        return;
      }

      // Construction payload
      const fd = new FormData();
      fd.append('source_type', 'chantier');
      fd.append('source_id', sourceCh);
      fd.append('destination_type', destination_type);
      fd.append('destination_id', String(destination_id));
      fd.append('article_id', articleId);
      fd.append('quantity', String(qty));

      try {
        const resp = await fetch(ACTION_URL, {
          method: 'POST',
          body: fd,
          headers: { 'X-Requested-With': 'fetch' }
        });

        const data = await resp.json().catch(() => ({}));

        if (!resp.ok || data.success === false) {
          const msg = data.message || 'Erreur lors du transfert.';
          showToast(msg, 'danger');
          return;
        }

        // Succès : MAJ UI
        updateRowAfterTransfer(articleId, qty);

        showToast(data.message || 'Transfert effectué.', 'success');
        transferModal && transferModal.hide();

      } catch (err) {
        showToast('Impossible de contacter le serveur.', 'danger');
        console.error(err);
      }
    });
  }

  /**
   * Met à jour la ligne : décrémente la badge quantité,
   * supprime la ligne si 0, surligne 3s en vert.
   */
  function updateRowAfterTransfer(articleId, qtySent) {
    // Trouver la ligne par l'anchor article.php?id=...
    const row = rows.find(tr => {
      const a = tr.querySelector('a.article-name');
      if (!a) return false;
      const url = new URL(a.getAttribute('href'), window.location.origin);
      const idParam = url.searchParams.get('id');
      return String(idParam) === String(articleId);
    });

    if (!row) return;

    const badge = row.querySelector('.badge');
    if (!badge) return;

    const current = parseInt(badge.textContent || '0', 10) || 0;
    const next = Math.max(0, current - qtySent);
    badge.textContent = String(next);
    badge.classList.toggle('bg-danger', next < 10);
    badge.classList.toggle('bg-success', next >= 10);

    // Surligner la ligne 3s
    row.classList.add('table-success');
    setTimeout(() => row.classList.remove('table-success'), 3000);

    // Si 0 => retirer la ligne
    if (next === 0) {
      row.parentElement.removeChild(row);
    }
  }
});
