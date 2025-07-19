document.addEventListener("DOMContentLoaded", () => {
    const searchInput = document.getElementById("searchInput");
    const tableRows = document.querySelectorAll(".stockTableBody tr");
    const subCategoriesSlide = document.getElementById("subCategoriesSlide");

    let currentCategory = "";
    let currentSubCategory = "";

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

        const subs = subCategories[cat] || [];
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
        const search = searchInput.value.toLowerCase().trim();

        tableRows.forEach(row => {
            const rowCat = (row.dataset.cat || "").toLowerCase().trim();
            const rowSub = (row.dataset.subcat || "").toLowerCase().trim();
            const name = row.querySelector("td:first-child").textContent.toLowerCase();

            const matchCat = !currentCategory || rowCat === currentCategory;
            const matchSub = !currentSubCategory || rowSub === currentSubCategory;
            const matchSearch = !search || name.includes(search);

            row.style.display = (matchCat && matchSub && matchSearch) ? "" : "none";
        });
    }

    searchInput.addEventListener("input", () => {
        filterRows();
    });

    filterByCategory('');
});

