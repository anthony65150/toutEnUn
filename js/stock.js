document.addEventListener("DOMContentLoaded", () => {
    const searchInput = document.getElementById("searchInput");
    const tableRows = document.querySelectorAll("#stockTableBody tr");
    const subCategoriesSlide = document.getElementById("subCategoriesSlide");

    let currentCategory = "";

    // Recherche
    searchInput.addEventListener("input", () => {
        const search = searchInput.value.toLowerCase();
        tableRows.forEach(row => {
            const name = row.querySelector("td:first-child").textContent.toLowerCase();
            row.style.display = name.includes(search) ? "" : "none";
        });
    });

    // Filtrage par catégorie
    window.filterByCategory = function (cat) {
        currentCategory = cat;
        document.querySelectorAll("#categoriesSlide button").forEach(btn => btn.classList.remove("active"));
        [...document.querySelectorAll("#categoriesSlide button")].find(b => b.textContent.toLowerCase().includes(cat.toLowerCase()))?.classList.add("active");

        updateSubCategories(cat);
        filterRows();
    };

    // Mise à jour des sous-catégories dynamiquement
    function updateSubCategories(cat) {
        subCategoriesSlide.innerHTML = '';
        const subs = subCategories[cat] || [];

        if (subs.length > 0) {

            subs.forEach(sub => {
                const btn = document.createElement("button");
                btn.className = "btn btn-outline-secondary";
                btn.textContent = sub;
                btn.onclick = () => {
                    document.querySelectorAll("#subCategoriesSlide button").forEach(b => b.classList.remove("active"));
                    btn.classList.add("active");
                    filterRows(sub);
                };
                subCategoriesSlide.appendChild(btn);
            });
        }
    }

    // Filtrage des lignes
    function filterRows(subCat = '') {
        tableRows.forEach(row => {
            const rowCat = row.dataset.cat;
            const rowSub = row.dataset.subcat;

            const matchCat = !currentCategory || rowCat === currentCategory;
            const matchSub = !subCat || rowSub === subCat;

            row.style.display = (matchCat && matchSub) ? "" : "none";
        });
    }

    // Initialisation
    filterByCategory('');
});
