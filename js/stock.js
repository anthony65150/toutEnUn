document.addEventListener("DOMContentLoaded", () => {
    const searchInput = document.getElementById("searchInput");
    const tableRows = document.querySelectorAll("#stockTableBody tr");
    const subCategoriesSlide = document.getElementById("subCategoriesSlide");

    let currentCategory = "";
    let currentSubCategory = "";

    // Recherche texte
    searchInput.addEventListener("input", () => {
        const search = searchInput.value.toLowerCase();
        tableRows.forEach(row => {
            const name = row.querySelector("td:first-child").textContent.toLowerCase();
            if ((name.includes(search)) &&
                (!currentCategory || row.dataset.cat === currentCategory) &&
                (!currentSubCategory || row.dataset.subcat === currentSubCategory)) {
                row.style.display = "";
            } else {
                row.style.display = "none";
            }
        });
    });

    // Filtrage par catégorie
    window.filterByCategory = function (cat) {
        currentCategory = cat;
        currentSubCategory = "";
        document.querySelectorAll("#categoriesSlide button").forEach(btn => btn.classList.remove("active"));
        [...document.querySelectorAll("#categoriesSlide button")]
            .find(b => b.textContent.toLowerCase().includes(cat.toLowerCase()))
            ?.classList.add("active");

        updateSubCategories(cat);
        filterRows();
    };

    // Mise à jour sous-catégories dynamiquement
    function updateSubCategories(cat) {
        subCategoriesSlide.innerHTML = '';
        const subs = subCategories[cat] || [];

        subs.forEach(sub => {
            const btn = document.createElement("button");
            btn.className = "btn btn-outline-secondary";
            btn.textContent = sub.charAt(0).toUpperCase() + sub.slice(1);
            btn.onclick = () => {
                currentSubCategory = sub;
                document.querySelectorAll("#subCategoriesSlide button").forEach(b => b.classList.remove("active"));
                btn.classList.add("active");
                filterRows();
            };
            subCategoriesSlide.appendChild(btn);
        });
    }

    // Filtrage des lignes (catégorie + sous-catégorie + recherche)
    function filterRows() {
        const search = searchInput.value.toLowerCase();

        tableRows.forEach(row => {
            const rowCat = row.dataset.cat;
            const rowSub = row.dataset.subcat;
            const name = row.querySelector("td:first-child").textContent.toLowerCase();

            const matchCat = !currentCategory || rowCat === currentCategory;
            const matchSub = !currentSubCategory || rowSub === currentSubCategory;
            const matchSearch = name.includes(search);

            row.style.display = (matchCat && matchSub && matchSearch) ? "" : "none";
        });
    }

    // Initialisation
    filterByCategory('');
});
