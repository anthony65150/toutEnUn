document.addEventListener("DOMContentLoaded", () => {
  const $ = (sel, ctx = document) => ctx.querySelector(sel);
  const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

  const searchInput = $("#searchInput");
  const subCategoriesSlide = $("#subCategoriesSlide");

  // tbody : accepte id="stockTableBody" OU class="stockTableBody"
  const tbody = $("#stockTableBody") || $(".stockTableBody");
  const tableRows = tbody ? $$("tr", tbody) : [];

  // state filtres
  let currentCategory = "";
  let currentSubCategory = "";

  // --- helpers ---
  const norm = (s) => (s ?? "").toString().toLowerCase().trim();

  function getRowText(row) {
    // Le nom d’article est dans la cellule .td-article (pas la 1ère colonne photo)
    const nameCell = row.querySelector(".td-article");
    return norm(nameCell?.textContent || row.textContent || "");
  }

  // --- recherche texte ---
  function filterRows() {
    const search = norm(searchInput?.value);

    tableRows.forEach(row => {
      const rowCat = norm(row.dataset.cat);
      const rowSub = norm(row.dataset.subcat);
      const name   = getRowText(row);

      const matchCat    = !currentCategory || rowCat === currentCategory;
      const matchSub    = !currentSubCategory || rowSub === currentSubCategory;
      const matchSearch = !search || name.includes(search);

      row.style.display = (matchCat && matchSub && matchSearch) ? "" : "none";
    });
  }

  searchInput?.addEventListener("input", filterRows);

  // --- catégories / sous-catégories ---
  window.filterByCategory = function (cat) {
    currentCategory = norm(cat);
    currentSubCategory = "";

    // état visuel des boutons catégories
    $$("#categoriesSlide button").forEach(btn => btn.classList.remove("active"));
    const catButtons = $$("#categoriesSlide button");
    const btnToActivate = catButtons.find(b => norm(b.textContent) === currentCategory);
    btnToActivate?.classList.add("active");

    updateSubCategories(currentCategory);
    filterRows();
  };

  function updateSubCategories(catKey) {
    if (!subCategoriesSlide) return;
    subCategoriesSlide.innerHTML = "";

    if (!catKey) return;

    // subCategories fourni par PHP : { "cat": ["sub1","sub2",...] }
    const subs = (window.subCategories?.[catKey]) || [];
    subs.forEach(sub => {
      const subKey = norm(sub);
      const btn = document.createElement("button");
      btn.className = "btn btn-outline-secondary";
      // Affichage lisible (Capitaliser première lettre)
      btn.textContent = subKey ? subKey.charAt(0).toUpperCase() + subKey.slice(1) : sub;

      btn.onclick = () => {
        currentSubCategory = subKey;
        $$("#subCategoriesSlide button").forEach(b => b.classList.remove("active"));
        btn.classList.add("active");
        filterRows();
      };

      subCategoriesSlide.appendChild(btn);
    });
  }

  // --- init ---
  filterByCategory("");
});
