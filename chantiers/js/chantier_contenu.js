// /js/chantier_contenu.js
document.addEventListener('DOMContentLoaded', () => {
  /* =========================
     Toast
     ========================= */
  const toastEl = document.getElementById('toastMessage');
  const toast = toastEl ? new bootstrap.Toast(toastEl) : null;
  function showToast(message, type = 'primary') {
    if (!toastEl) return alert(message);
    toastEl.classList.remove('text-bg-primary','text-bg-success','text-bg-danger','text-bg-warning','text-bg-info');
    toastEl.classList.add(`text-bg-${type}`);
    toastEl.querySelector('.toast-body').textContent = message;
    toast?.show();
  }

  /* =========================
     Recherche + Filtres
     ========================= */
  const searchInput = document.getElementById('searchInput');
  const tableBody   = document.getElementById('stockTableBody');
  const catSlide    = document.getElementById('categoriesSlide');
  const subSlide    = document.getElementById('subCategoriesSlide');

  let currentCat = '';
  let currentSub = '';

  const normalize = (s) =>
    (s ?? '')
      .toString()
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .trim();

  const getRows = () =>
    Array.from(tableBody?.querySelectorAll('tr') || []).filter(tr => tr.id !== 'noResultsRow');

  function ensureNoResultsRow(show) {
    let row = document.getElementById('noResultsRow');
    if (!row) {
      row = document.createElement('tr');
      row.id = 'noResultsRow';
      row.innerHTML = `<td colspan="4" class="text-center text-muted py-4">Aucun article ne correspond à votre recherche.</td>`;
      tableBody?.appendChild(row);
    }
    row.style.display = show ? '' : 'none';
  }

  function setActiveBtn(container, btn) {
    container.querySelectorAll('button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
  }

  function applyFilters() {
    const q = normalize(searchInput?.value || '');
    let visible = 0;

    getRows().forEach(tr => {
      const aName  = tr.querySelector('.article-name');
      const rowCat = normalize(tr.getAttribute('data-cat') || '');
      const rowSub = normalize(tr.getAttribute('data-subcat') || '');
      const txt    = normalize(aName ? aName.textContent : tr.textContent);

      const matchSearch = !q || txt.includes(q);
      const matchCat    = !currentCat || rowCat === normalize(currentCat);
      const matchSub    = !currentSub || rowSub === normalize(currentSub);

      const show = matchSearch && matchCat && matchSub;
      tr.style.display = show ? '' : 'none';
      if (show) visible++;
    });

    ensureNoResultsRow(visible === 0);
  }

  function rebuildSubCats() {
    subSlide.innerHTML = '';
    if (!currentCat) return;

    const subs = new Set();
    getRows().forEach(tr => {
      const rowCat = normalize(tr.getAttribute('data-cat') || '');
      if (rowCat === normalize(currentCat)) {
        const s = tr.getAttribute('data-subcat') || '';
        if (s) subs.add(s);
      }
    });
    if (subs.size === 0) return;

    Array.from(subs)
      .sort((a,b)=>a.localeCompare(b, undefined, {sensitivity:'base'}))
      .forEach(s => {
        const btn = document.createElement('button');
        btn.className = 'btn btn-outline-secondary';
        btn.textContent = s.charAt(0).toUpperCase() + s.slice(1);
        btn.addEventListener('click', () => {
          currentSub = s;
          setActiveBtn(subSlide, btn);
          applyFilters();
        });
        subSlide.appendChild(btn);
      });
  }

  // Recherche (debounce)
  if (searchInput) {
    let t;
    searchInput.addEventListener('input', () => {
      clearTimeout(t);
      t = setTimeout(applyFilters, 120);
    });
  }

  // Catégories
  if (catSlide) {
    catSlide.querySelectorAll('button').forEach(btn => {
      btn.addEventListener('click', () => {
        currentCat = btn.getAttribute('data-cat') || '';
        currentSub = ''; // reset subcat quand on change de catégorie
        setActiveBtn(catSlide, btn);
        rebuildSubCats();
        applyFilters();
      });
    });
    const firstBtn = catSlide.querySelector('button');
    if (firstBtn) firstBtn.classList.add('active');
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

  // Délégation : ouvrir la modale en récupérant les data-*
  document.addEventListener('click', (ev) => {
    const btn = ev.target.closest('.transfer-btn');
    if (!btn) return;

    const articleId  = btn.getAttribute('data-stock-id');
    const articleNom = btn.getAttribute('data-stock-nom') || 'Article';
    const sourceId   = btn.getAttribute('data-source-id');

    if (!inputArticleId || !inputSourceChId) return;

    inputArticleId.value  = articleId || '';
    inputSourceChId.value = sourceId || '';

    const titleEl = modalEl?.querySelector('#transferModalLabel');
    if (titleEl) titleEl.textContent = `Transférer : ${articleNom}`;

    if (selectDestination) selectDestination.value = '';
    if (inputQuantity) inputQuantity.value = '';

    transferModal?.show();
  });

  /* =========================
     Soumission du transfert
     ========================= */
  // Ton fichier PHP est situé dans /stock/
  const ACTION_URL = '../stock/transferStock_chef.php';

  if (transferForm) {
    transferForm.addEventListener('submit', async (e) => {
      e.preventDefault();

      const articleId = inputArticleId?.value || '';
      const sourceCh  = inputSourceChId?.value || '';
      const destRaw   = selectDestination?.value || '';
      const qty       = parseInt(inputQuantity?.value || '0', 10);

      if (!articleId || !sourceCh || !destRaw || !qty || qty < 1) {
        showToast('Veuillez remplir tous les champs.', 'warning');
        return;
      }

      const m = destRaw.match(/^(depot|chantier)_(\d+)$/);
      if (!m) {
        showToast('Destination invalide.', 'danger');
        return;
      }
      const destination_type = m[1];
      const destination_id   = parseInt(m[2], 10);

      const fd = new FormData();
      fd.append('source_type', 'chantier');
      fd.append('source_id', sourceCh);
      fd.append('destination_type', destination_type);
      fd.append('destination_id', String(destination_id));
      fd.append('article_id', articleId);
      fd.append('quantity', String(qty));
      if (window.CSRF_TOKEN) fd.append('csrf_token', String(window.CSRF_TOKEN));

      try {
        const resp = await fetch(ACTION_URL, {
          method: 'POST',
          body: fd,
          headers: { 'X-Requested-With': 'fetch' }
        });

        let data = {};
        try { data = await resp.json(); } catch {}

        if (!resp.ok || data.success === false) {
          showToast(data.message || 'Erreur lors du transfert.', 'danger');
          return;
        }

        updateRowAfterTransfer(articleId, qty);
        showToast(data.message || 'Transfert effectué.', 'success');
        transferModal?.hide();

      } catch (err) {
        console.error(err);
        showToast('Impossible de contacter le serveur.', 'danger');
      }
    });
  }

  /**
   * Met à jour la ligne : décrémente la quantité,
   * supprime la ligne si 0, surlignage 3s.
   */
  function updateRowAfterTransfer(articleId, qtySent) {
    const row = getRows().find(tr => {
      const a = tr.querySelector('a.article-name');
      if (!a) return false;
      try {
        const url = new URL(a.getAttribute('href'), window.location.origin);
        return url.searchParams.get('id') === String(articleId);
      } catch {
        const href = a.getAttribute('href') || '';
        const m = href.match(/[?&]id=(\d+)/);
        return m && m[1] === String(articleId);
      }
    });

    if (!row) return;

    const badge = row.querySelector('.badge');
    if (!badge) return;

    const current = parseInt(badge.textContent || '0', 10) || 0;
    const next = Math.max(0, current - qtySent);
    badge.textContent = String(next);
    badge.classList.toggle('bg-danger', next < 10);
    badge.classList.toggle('bg-success', next >= 10);

    row.classList.add('table-success');
    setTimeout(() => row.classList.remove('table-success'), 3000);

    if (next === 0) {
      row.remove();
      const anyVisible = getRows().some(tr => tr.style.display !== 'none');
      ensureNoResultsRow(!anyVisible);
    }
  }
});
