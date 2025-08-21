document.addEventListener("DOMContentLoaded", () => {
  const searchInput = document.getElementById("searchInput");
  const subCategoriesSlide = document.getElementById("subCategoriesSlide");

  const tbody = document.getElementById("stockTableBody") || document.querySelector(".stockTableBody");
  const tableRows = tbody ? tbody.querySelectorAll("tr") : [];

  let currentCategory = "";
  let currentSubCategory = "";

  // ----- Toast + modal setup -----
  const toastElement = document.getElementById('toastMessage');
  const toastBootstrap = new bootstrap.Toast(toastElement);
  const toastBody = toastElement.querySelector('.toast-body');

  function showToast(message, isSuccess = true) {
    toastElement.classList.remove('text-bg-success', 'text-bg-danger');
    toastElement.classList.add(isSuccess ? 'text-bg-success' : 'text-bg-danger');
    toastBody.textContent = message;
    toastBootstrap.show();
  }

  const transferModal = new bootstrap.Modal(document.getElementById('transferModal'));

  // ----- Ouvrir le modal -----
  document.querySelectorAll('.transfer-btn').forEach(button => {
    button.addEventListener('click', () => {
      const stockId = button.dataset.stockId;
      document.getElementById('articleId').value = stockId;

      const tr = button.closest('tr');
      const nameCell = tr?.querySelector('.td-article');
      const articleName = (nameCell?.querySelector('a')?.textContent || nameCell?.textContent || '').trim();

      document.getElementById('transferModalLabel').textContent = `Transférer : ${articleName}`;
      transferModal.show();
    });
  });

  // ----- Soumission du transfert -----
  document.getElementById('transferForm').addEventListener('submit', (e) => {
    e.preventDefault();

    const stockId = document.getElementById('articleId').value;
    const destinationRaw = document.getElementById('destinationChantier').value;
    const quantity = document.getElementById('quantity').value;

    let sourceType = 'depot';
    let sourceId = null;

    if (window.isChef) { // chef : ID du chantier courant
      sourceType = 'chantier';
      sourceId = parseInt(window.chefChantierActuel);
    } else {
      // dépôt : lire l’input caché
      sourceId = parseInt(document.getElementById('sourceDepotId')?.value || '0', 10);
    }

    const [destinationType, destinationId] = destinationRaw.split('_');

    const data = {
      stockId: parseInt(stockId),
      sourceType,
      sourceId: parseInt(sourceId),
      destinationType,
      destinationId: parseInt(destinationId),
      qty: parseInt(quantity)
    };

    fetch('transferStock_depot.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        showToast('✅ ' + data.message, true);
        setTimeout(() => location.reload(), 1500);
      } else {
        showToast('❌ ' + (data.message || 'Échec du transfert'), false);
      }
    })
    .catch(err => {
      console.error(err);
      showToast('❌ Erreur lors de la requête', false);
    });

    const highlighted = document.querySelector("tr.highlight-row");
    if (highlighted) {
      setTimeout(() => {
        highlighted.classList.remove("highlight-row", "table-success");
      }, 3000);
    }
  });

  // ----- Filtres catégories / sous-catégories -----
  window.filterByCategory = (cat) => {
    currentCategory = (cat || "").toLowerCase().trim();
    currentSubCategory = "";

    document.querySelectorAll("#categoriesSlide button").forEach(b => b.classList.remove("active"));
    [...document.querySelectorAll("#categoriesSlide button")]
      .find(b => b.textContent.toLowerCase().trim() === currentCategory)
      ?.classList.add("active");

    updateSubCategories(currentCategory);
    filterRows();
  };

  function updateSubCategories(cat) {
    subCategoriesSlide.innerHTML = '';
    if (!cat) return;

    const subs = (window.subCategories?.[cat]) || [];
    subs.forEach(sub => {
      const subNormalized = sub.toLowerCase().trim();
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

    tableRows.forEach(row => {
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
