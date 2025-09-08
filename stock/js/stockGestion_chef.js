document.addEventListener("DOMContentLoaded", () => {
  // --- Vérif bootstrap présent ---
  if (typeof bootstrap === "undefined") {
    console.warn("[stockGestion_chef] Bootstrap JS introuvable. Charge bootstrap.bundle.min.js avant ce script.");
  }

  const searchInput = document.getElementById("searchInput");
  const subCategoriesSlide = document.getElementById("subCategoriesSlide");
  const tbody = document.getElementById("stockTableBody") || document.querySelector(".stockTableBody");
  const tableRows = tbody ? tbody.querySelectorAll("tr") : [];

  let currentCategory = "";
  let currentSubCategory = "";

  // Toast
  const toastElement = document.getElementById('toastMessage');
  const toastBootstrap = toastElement ? new bootstrap.Toast(toastElement) : null;
  const toastBody = toastElement ? toastElement.querySelector('.toast-body') : null;
  const showToast = (msg, ok = true) => {
    if (!toastElement || !toastBootstrap || !toastBody) {
      console.warn("[stockGestion_chef] Toast non présent dans le DOM.");
      return;
    }
    toastElement.classList.remove('text-bg-success', 'text-bg-danger');
    toastElement.classList.add(ok ? 'text-bg-success' : 'text-bg-danger');
    toastBody.textContent = msg;
    toastBootstrap.show();
  };

  // Modal
  const transferModalEl = document.getElementById('transferModal');
  const transferModal = transferModalEl && typeof bootstrap !== "undefined"
    ? new bootstrap.Modal(transferModalEl)
    : null;

  // --- Délégation pour .transfer-btn (marche même si lignes remplacées après coup) ---
  document.addEventListener('click', (e) => {
    const button = e.target.closest('.transfer-btn');
    if (!button) return;

    if (!transferModal) {
      console.warn("[stockGestion_chef] Modal non initialisée (ID manquant ou Bootstrap non chargé).");
      showToast("❌ Erreur d’interface : modal indisponible", false);
      return;
    }

    const stockId = button.dataset.stockId;
    const inputId = document.getElementById('articleId');
    if (inputId) inputId.value = stockId;

    const tr = button.closest('tr');
    const nameCell = tr?.querySelector('.td-article');
    const articleName = (nameCell?.querySelector('a')?.textContent || nameCell?.textContent || '').trim();

    const titleEl = document.getElementById('transferModalLabel');
    if (titleEl) titleEl.textContent = `Transférer : ${articleName}`;

    transferModal.show();
  });

  // --- Soumission du transfert ---
  const transferForm = document.getElementById('transferForm');
  transferForm?.addEventListener('submit', (e) => {
    e.preventDefault();

    const stockId = document.getElementById('articleId')?.value;
    const destinationRaw = document.getElementById('destinationChantier')?.value || '';
    const quantity = document.getElementById('quantity')?.value;

    if (!destinationRaw) return showToast('❌ Choisis une destination', false);
    if (!quantity || parseInt(quantity, 10) <= 0) return showToast('❌ Quantité invalide', false);

    let sourceType = 'depot';
    let sourceId = 0;

    if (window.isChef) {
      sourceType = 'chantier';
      sourceId = parseInt(window.chefChantierActuel || '0', 10);
    } else {
      sourceId = parseInt(document.getElementById('sourceDepotId')?.value || '0', 10);
    }

    const parts = destinationRaw.split('_');
    if (parts.length !== 2) return showToast('❌ Destination invalide', false);
    const [destinationType, destinationIdStr] = parts;

    const payload = {
      stockId: parseInt(stockId, 10),
      sourceType,
      sourceId: parseInt(sourceId, 10),
      destinationType,
      destinationId: parseInt(destinationIdStr, 10),
      qty: parseInt(quantity, 10)
    };

    fetch('transferStock_chef.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    .then(async (res) => {
      let data;
      try { data = await res.json(); } catch { data = { success:false, message:"Réponse invalide du serveur" }; }
      if (data?.success) {
        showToast('✅ ' + (data.message || 'Transfert envoyé'));
        setTimeout(() => location.reload(), 1200);
      } else {
        showToast('❌ ' + (data?.message || 'Échec du transfert'), false);
      }
    })
    .catch((err) => {
      console.error(err);
      showToast('❌ Erreur réseau', false);
    });
  });

  // --- Filtres catégories / sous-catégories ---
  window.filterByCategory = (cat) => {
    currentCategory = (cat || "").toLowerCase().trim();
    currentSubCategory = "";

    document.querySelectorAll("#categoriesSlide button").forEach(b => b.classList.remove("active"));
    const btnToActivate = [...document.querySelectorAll("#categoriesSlide button")]
      .find(b => (b.textContent || '').toLowerCase().trim() === currentCategory);
    btnToActivate?.classList.add("active");

    updateSubCategories(currentCategory);
    filterRows();
  };

  function updateSubCategories(cat) {
    if (!subCategoriesSlide) return;
    subCategoriesSlide.innerHTML = '';
    if (!cat) return;

    const subs = (window.subCategories?.[cat]) || [];
    subs.forEach(sub => {
      const subNormalized = (sub || '').toLowerCase().trim();
      const btn = document.createElement("button");
      btn.className = "btn btn-outline-secondary";
      btn.textContent = sub;
      btn.onclick = () => {
        currentSubCategory = subNormalized;
        document.querySelectorAll("#subCategoriesSlide button").forEach(b => b.classList.remove("active"));
        btn.classList.add("active");
        filterRows();
      };
      subCategoriesSlide.appendChild(btn);
    });
  }

  function filterRows() {
    const search = (searchInput?.value || '').toLowerCase().trim();
    (tbody ? tbody.querySelectorAll("tr") : []).forEach(row => {
      const rowCat = (row.dataset.cat || "").toLowerCase().trim();
      const rowSub = (row.dataset.subcat || "").toLowerCase().trim();
      const nameCell = row.querySelector(".td-article");
      const hay = (nameCell?.textContent || row.textContent || '').toLowerCase().trim();

      const matchCat = !currentCategory || rowCat === currentCategory;
      const matchSub = !currentSubCategory || rowSub === currentSubCategory;
      const matchSearch = !search || hay.includes(search);

      row.style.display = (matchCat && matchSub && matchSearch) ? "" : "none";
    });
  }

  searchInput?.addEventListener("input", filterRows);
  filterByCategory('');
});
