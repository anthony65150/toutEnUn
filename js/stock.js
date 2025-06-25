// Gestion des catégories et sous-catégories
const categoriesSlide = document.getElementById('categoriesSlide');
const subCategoriesSlide = document.getElementById('subCategoriesSlide');
const stockTableBody = document.getElementById('stockTableBody');
const searchInput = document.getElementById('searchInput');

let selectedCategory = null;
let selectedSubCategory = null;

// Sous-catégories par catégorie (à adapter selon tes données)
const subCategoriesData = {
    'Étais': ['Étais métalliques', 'Étais bois'],
    'Banches': ['Banches bois', 'Banches métal'],
    'Madriers': ['Madriers 3m', 'Madriers 4m'],
    'Planches': ['Planches brutes', 'Planches traitées'],
    'Coffrages': ['Coffrages modulaires', 'Coffrages fixes'],
    'Échelles': ['Échelles pliantes', 'Échelles fixes'],
    'Niveaux': ['Niveaux laser', 'Niveaux à bulle'],
    'Autres': ['Divers']
};

// Fonction pour créer un bouton
function createButton(text) {
    const btn = document.createElement('button');
    btn.className = 'btn btn-outline-primary flex-shrink-0';
    btn.textContent = text;
    return btn;
}

// Afficher sous-catégories selon catégorie choisie
function displaySubCategories(cat) {
    subCategoriesSlide.innerHTML = '';
    selectedSubCategory = null;

    if (!cat || !subCategoriesData[cat]) return;

    // Bouton "Tous"
    const btnAll = createButton('Tous');
    btnAll.classList.add('active');
    btnAll.addEventListener('click', () => {
        selectedSubCategory = null;
        updateActiveButtons(subCategoriesSlide, btnAll);
        filterTable(selectedCategory, selectedSubCategory, searchInput.value.toLowerCase());
    });
    subCategoriesSlide.appendChild(btnAll);

    subCategoriesData[cat].forEach(subcat => {
        const btn = createButton(subcat);
        btn.addEventListener('click', () => {
            selectedSubCategory = subcat;
            updateActiveButtons(subCategoriesSlide, btn);
            filterTable(selectedCategory, selectedSubCategory, searchInput.value.toLowerCase());
        });
        subCategoriesSlide.appendChild(btn);
    });
}

// Met à jour les boutons actifs dans un conteneur
function updateActiveButtons(container, activeBtn) {
    [...container.querySelectorAll('button')].forEach(b => b.classList.remove('active'));
    activeBtn.classList.add('active');
}

// Filtrage des lignes du tableau selon catégorie, sous-catégorie, recherche sur nom uniquement
function normalize(str) {
    return str.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();
}

document.getElementById('searchInput').addEventListener('input', function () {
    const searchValue = normalize(this.value.trim());
    const rows = document.querySelectorAll('#stockTableBody tr');

    rows.forEach(row => {
        const nom = row.querySelector('td:nth-child(1)').textContent;
        const normalizedNom = normalize(nom.trim());

        // Affiche uniquement si le nom commence par la recherche
        row.style.display = normalizedNom.startsWith(searchValue) ? '' : 'none';
    });
});

// Événement clic catégorie
categoriesSlide.querySelectorAll('button').forEach(btn => {
    btn.addEventListener('click', () => {
        selectedCategory = btn.textContent === selectedCategory ? null : btn.textContent;
        updateActiveButtons(categoriesSlide, btn);
        displaySubCategories(selectedCategory);
        filterTable(selectedCategory, selectedSubCategory, searchInput.value.toLowerCase());
    });
});

// Événement saisie dans la recherche (filtre sur nom uniquement)
searchInput.addEventListener('input', () => {
    filterTable(selectedCategory, selectedSubCategory, searchInput.value.toLowerCase());
});

// Au chargement, on affiche tout sans sélection
filterTable(null, null, '');
