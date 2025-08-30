document.addEventListener("DOMContentLoaded", () => {
  // ===========================
  // FILTRES / RECHERCHE
  // ===========================
  const subCategories = window.subCategories || {};
  const $catsWrap = document.getElementById("categoriesSlide");
  const $subsWrap = document.getElementById("subCategoriesSlide");
  const $rowsBody = document.getElementById("stockTableBody");
  const $search   = document.getElementById("searchInput");

  let currentCat = "";
  let currentSub = "";
  let searchTerm = "";

  function normalize(str) {
    return (str || "")
      .toString()
      .toLowerCase()
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, ""); // retire accents (compatible large)
  }

  function renderSubCats(cat) {
    $subsWrap.innerHTML = "";
    if (!cat) return;
    const list = subCategories[cat] || [];
    list.forEach((sc) => {
      const b = document.createElement("button");
      b.className = "btn btn-outline-secondary";
      b.dataset.subcat = sc;
      b.textContent = sc;
      $subsWrap.appendChild(b);
    });
  }

  function applyFilters() {
    const rows = $rowsBody.querySelectorAll("tr");
    const s = normalize(searchTerm);

    rows.forEach((tr) => {
      if (!tr.hasAttribute("data-cat")) { tr.style.display = ""; return; }
      const cat = tr.getAttribute("data-cat") || "";
      const sub = tr.getAttribute("data-subcat") || "";
      const nameEl = tr.querySelector(".article-name");
      const text = normalize(nameEl ? nameEl.textContent : tr.textContent);

      const okCat = !currentCat || cat === currentCat;
      const okSub = !currentSub || sub === currentSub;
      const okSearch = !s || text.includes(s);

      tr.style.display = okCat && okSub && okSearch ? "" : "none";
    });
  }

  // Catégories (délégation)
  $catsWrap.addEventListener("click", (e) => {
    const btn = e.target.closest("button[data-cat]");
    if (!btn) return;
    $catsWrap.querySelectorAll("button").forEach((b) => b.classList.remove("active"));
    btn.classList.add("active");

    currentCat = btn.dataset.cat || "";
    currentSub = "";
    renderSubCats(currentCat);
    $subsWrap.querySelectorAll("button").forEach((b) => b.classList.remove("active"));
    applyFilters();
  });

  // Sous-catégories
  $subsWrap.addEventListener("click", (e) => {
    const btn = e.target.closest("button[data-subcat]");
    if (!btn) return;
    $subsWrap.querySelectorAll("button").forEach((b) => b.classList.remove("active"));
    btn.classList.add("active");

    currentSub = btn.dataset.subcat || "";
    applyFilters();
  });

  // Recherche
  $search.addEventListener("input", (e) => {
    searchTerm = e.target.value || "";
    applyFilters();
  });

  // Activer "Tous" par défaut
  const firstCatBtn = $catsWrap.querySelector('button[data-cat=""]');
  if (firstCatBtn) firstCatBtn.classList.add("active");


  // ===========================
  // MODALE TRANSFÉRER
  // ===========================
  const ENDPOINT = "/stock/transferStock_depot.php";

  const transferModalEl   = document.getElementById("transferModal");
  const transferForm      = document.getElementById("transferForm");
  const articleIdInput    = document.getElementById("articleId");
  const destinationSelect = document.getElementById("destinationChantier");
  const quantityInput     = document.getElementById("quantity");
  const toastEl           = document.getElementById("toastMessage");
  const toast             = toastEl ? new bootstrap.Toast(toastEl) : null;
  const toastBody         = toastEl ? toastEl.querySelector(".toast-body") : null;

  let currentRow = null; // pour MAJ visuelle après transfert

  function showToast(msg, ok = true) {
    if (!toastEl) { alert(msg); return; }
    toastEl.classList.remove("text-bg-success", "text-bg-danger", "text-bg-primary");
    toastEl.classList.add(ok ? "text-bg-success" : "text-bg-danger");
    toastBody.textContent = msg;
    toast.show();
  }

  // Ouvrir la modale via le bouton
  $rowsBody.addEventListener("click", (e) => {
    const btn = e.target.closest(".transfer-btn");
    if (!btn) return;

    const stockId = btn.dataset.stockId;
    const stockNom = btn.dataset.stockNom || "";
    articleIdInput.value = stockId;
    quantityInput.value = "";
    destinationSelect.value = "";

    currentRow = btn.closest("tr");

    const title = transferModalEl.querySelector(".modal-title");
    if (title && stockNom) {
      title.textContent = "Transférer — " + stockNom;
    }

    const modal = new bootstrap.Modal(transferModalEl);
    modal.show();
  });

  // Soumission du transfert
  transferForm.addEventListener("submit", async (e) => {
    e.preventDefault();

    const dest = destinationSelect.value;
    const qty = parseInt(quantityInput.value, 10);

    if (!dest) { showToast("Choisis une destination.", false); return; }
    if (!qty || qty < 1) { showToast("Quantité invalide.", false); return; }

    // "depot_2" -> type="depot", id=2
    const [destType, destId] = dest.split("_");
    const fd = new FormData(transferForm);
    fd.append("destination_type", destType);
    fd.append("destination_id", destId);

    try {
      const res = await fetch(ENDPOINT, { method: "POST", body: fd });
      const data = await res.json().catch(() => null);

      if (!data || !data.success) {
        throw new Error((data && data.message) || "Erreur transfert.");
      }

      // MAJ quantité dans la ligne
      if (currentRow) {
        const badge = currentRow.querySelector("td:nth-child(3) .badge");
        if (badge) {
          if (typeof data.new_quantite_depot !== "undefined") {
            const nv = parseInt(data.new_quantite_depot, 10);
            badge.textContent = isNaN(nv) ? badge.textContent : nv;
            const val = isNaN(nv) ? parseInt(badge.textContent, 10) : nv;
            badge.classList.toggle("bg-danger", val < 10);
            badge.classList.toggle("bg-success", val >= 10);
          } else {
            // fallback: décrémente localement
            const oldVal = parseInt(badge.textContent || "0", 10);
            const newVal = Math.max(0, (isNaN(oldVal) ? 0 : oldVal) - qty);
            badge.textContent = newVal;
            badge.classList.toggle("bg-danger", newVal < 10);
            badge.classList.toggle("bg-success", newVal >= 10);
          }
        }
        // Surbrillance 3s
        currentRow.classList.add("table-success");
        setTimeout(() => currentRow && currentRow.classList.remove("table-success"), 3000);
      }

      showToast(data.message || "Transfert enregistré.");
      bootstrap.Modal.getInstance(transferModalEl)?.hide();
    } catch (err) {
      showToast(err.message || "Erreur réseau.", false);
    }
  });
});
