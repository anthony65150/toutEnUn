document.addEventListener("DOMContentLoaded", () => {
    const searchInput = document.getElementById("searchInput");
    const tableRows = document.querySelectorAll("#stockTableBody tbody tr");
    const subCategoriesSlide = document.getElementById("subCategoriesSlide");
    const toastElement = document.getElementById('toastMessage');
const toastBootstrap = new bootstrap.Toast(toastElement);
const toastBody = toastElement.querySelector('.toast-body');

function showToast(message, isSuccess = true) {
    toastElement.classList.remove('text-bg-success', 'text-bg-danger');
    toastElement.classList.add(isSuccess ? 'text-bg-success' : 'text-bg-danger');
    toastBody.textContent = message;
    toastBootstrap.show();
}


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


    // ouvrir le modal et gérer le transfert
const transferModal = new bootstrap.Modal(document.getElementById('transferModal'));

document.querySelectorAll('.transfer-btn').forEach(button => {
    button.addEventListener('click', () => {
        const stockId = button.dataset.stockId;
        const stockNom = button.dataset.stockNom;

        document.getElementById('articleId').value = stockId;
        document.getElementById('transferModalLabel').textContent = `Transférer : ${stockNom}`;

        transferModal.show();
    });
});
document.getElementById('transferForm').addEventListener('submit', (e) => {
    e.preventDefault();

    const stockId = document.getElementById('articleId').value;
    const sourceDepotId = document.getElementById('sourceDepotId')?.value; // null pour chef
    const destinationRaw = document.getElementById('destinationChantier').value;
    const quantity = document.getElementById('quantity').value;

    let sourceType = 'depot';
    let sourceId = sourceDepotId;

    if (window.isChef) {  // tu peux définir window.isChef = true dans le fichier chef
        sourceType = 'chantier';
        sourceId = window.chefChantierId;  // tu mets ça au chargement PHP
    }

    const [destinationType, destinationId] = destinationRaw.split('_');

    const data = {
        stockId: parseInt(stockId),
        sourceType: sourceType,
        sourceId: parseInt(sourceId),
        destinationType: destinationType,
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

});



