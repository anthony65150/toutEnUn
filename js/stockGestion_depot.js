document.addEventListener("DOMContentLoaded", () => {
    const searchInput = document.getElementById("searchInput");
    const tableRows = document.querySelectorAll("#stockTableBody tbody tr");
    const subCategoriesSlide = document.getElementById("subCategoriesSlide");

    let currentCategory = "";
    let currentSubCategory = "";

    function normalize(str) {
        return (str || "").toLowerCase().trim();
    }

    window.filterByCategory = (cat) => {
        currentCategory = normalize(cat);
        currentSubCategory = "";

        document.querySelectorAll("#categoriesSlide button").forEach(btn => btn.classList.remove("active"));
        [...document.querySelectorAll("#categoriesSlide button")]
            .find(b => normalize(b.textContent) === currentCategory)
            ?.classList.add("active");

        updateSubCategories(currentCategory);
        filterRows();
    };

    function updateSubCategories(cat) {
        subCategoriesSlide.innerHTML = '';
        if (!cat) return;

        const subs = subCategories[cat] || [];
        subs.forEach(sub => {
            const subNormalized = normalize(sub);
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
        const search = normalize(searchInput.value);

        tableRows.forEach(row => {
            const rowCat = normalize(row.dataset.cat);
            const rowSub = normalize(row.dataset.subcat);
            const name = normalize(row.querySelector(".nom-article")?.textContent || "");

            const matchCat = !currentCategory || rowCat === currentCategory;
            const matchSub = !currentSubCategory || rowSub === currentSubCategory;
            const matchSearch = !search || name.includes(search);

            row.style.display = (matchCat && matchSub && matchSearch) ? "" : "none";
        });
    }

    searchInput.addEventListener("input", filterRows);
    filterByCategory('');
});
