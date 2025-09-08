document.addEventListener("DOMContentLoaded", () => {
  // ---------- Helpers ----------
  const $  = (sel, ctx = document) => ctx.querySelector(sel);
  const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

  // Normalisation: minuscules + suppression des accents + trim
  const norm = (s) =>
    (s ?? "")
      .toString()
      .toLowerCase()
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .trim();

  // Debounce simple
  const debounce = (fn, delay = 150) => {
    let t;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), delay);
    };
  };

  // ---------- DOM ----------
  const searchInput        = $("#searchInput");
  const subCategoriesSlide = $("#subCategoriesSlide");
  const tbody              = $("#stockTableBody") || $(".stockTableBody");
  const tableRows          = tbody ? $$("tr", tbody) : [];

  // Message "aucun résultat"
  let emptyRowMsg = $("#noResultsMsg");
  if (!emptyRowMsg && tbody) {
    emptyRowMsg = document.createElement("tr");
    emptyRowMsg.id = "noResultsMsg";
    emptyRowMsg.style.display = "none";
    emptyRowMsg.innerHTML = `<td colspan="5" class="text-center text-muted py-4">
      Aucun résultat
    </td>`;
    tbody.appendChild(emptyRowMsg);
  }

  // ---------- État filtres ----------
  let currentCategory = "";     // clé normalisée
  let currentSubCat   = "";     // clé normalisée

  // ---------- Index catégories/sous-catégories insensible à la casse/accents ----------
  // window.subCategories attendu: { "Catégorie": ["Sous1", "Sous2"] }
  const rawSub = window.subCategories || {};
  const subIndex = {}; // { norm(categorie): [ {label, key} ] }
  Object.entries(rawSub).forEach(([cat, subs]) => {
    const cKey = norm(cat);
    subIndex[cKey] = (subs || []).map((s) => ({ label: s, key: norm(s) }));
  });

  // ---------- Cache texte des lignes (perf pour grosses tables) ----------
  const rowCache = new Map(); // row -> { name, cat, sub }
  const getRowData = (row) => {
    if (rowCache.has(row)) return rowCache.get(row);
    const nameCell = row.querySelector(".td-article");
    const data = {
      name: norm(nameCell?.textContent || row.textContent || ""),
      cat:  norm(row.dataset.cat),
      sub:  norm(row.dataset.subcat),
    };
    rowCache.set(row, data);
    return data;
  };

  // ---------- Filtrage ----------
  const applyFilters = () => {
    const search = norm(searchInput?.value);
    let anyVisible = false;

    tableRows.forEach((row) => {
      if (row.id === "noResultsMsg") return; // ignore message
      const { name, cat, sub } = getRowData(row);

      const matchCat = !currentCategory || cat === currentCategory;
      const matchSub = !currentSubCat || sub === currentSubCat;
      const matchSearch = !search || name.includes(search);

      const show = matchCat && matchSub && matchSearch;
      row.style.display = show ? "" : "none";
      if (show) anyVisible = true;
    });

    if (emptyRowMsg) emptyRowMsg.style.display = anyVisible ? "none" : "";
  };

  const applyFiltersDebounced = debounce(applyFilters, 120);

  // ---------- UI Catégories ----------
  // Active visuellement le bon bouton dans #categoriesSlide
  const activateCategoryBtn = () => {
    $$("#categoriesSlide button").forEach((b) => b.classList.remove("active"));

    if (!currentCategory) {
      // bouton "Tous"
      const btnAll = $$("#categoriesSlide button").find(
        (b) => norm(b.textContent) === "tous"
      );
      btnAll?.classList.add("active");
      return;
    }
    const found = $$("#categoriesSlide button").find(
      (b) => norm(b.textContent) === currentCategory
    );
    found?.classList.add("active");
  };

  // Construit la rangée de sous-catégories pour la catégorie en cours
  const renderSubCategories = () => {
    if (!subCategoriesSlide) return;
    subCategoriesSlide.innerHTML = "";

    if (!currentCategory) return;

    const subs = subIndex[currentCategory] || [];
    subs.forEach(({ label, key }) => {
      const btn = document.createElement("button");
      btn.className = "btn btn-outline-secondary";
      // Joli label: si le back envoie déjà capitalisé, on garde; sinon on capitalise
      const pretty =
        label && label.length
          ? label.charAt(0).toUpperCase() + label.slice(1)
          : label;
      btn.textContent = pretty;

      btn.addEventListener("click", () => {
        currentSubCat = key;
        $$("#subCategoriesSlide button").forEach((b) =>
          b.classList.remove("active")
        );
        btn.classList.add("active");
        applyFilters();
      });

      subCategoriesSlide.appendChild(btn);
    });
  };

  // Exposé global pour les boutons de catégories (garde compat avec le PHP)
  window.filterByCategory = function (catLabel) {
    currentCategory = norm(catLabel || "");
    currentSubCat = ""; // reset sous-catégorie
    activateCategoryBtn();
    renderSubCategories();
    applyFilters();
  };

  // ---------- Recherche ----------
  searchInput?.addEventListener("input", applyFiltersDebounced);

  // ---------- Init ----------
  window.filterByCategory(""); // active "Tous", nettoie sous-cats et applique le filtre
  applyFilters();              // 1ère passe (au cas où)
});
