// ajoutStock.js

const sousCategoriesData = {
    etaiement: ["tours", "etais"],
    banches: ["métalliques", "manuportables"],
    "coffrage Peri": ["élement blanc"],
    echafaudages: ["3m", "2.5m", "2m"]
};

function updateSousCategorie() {
    const categorie = document.getElementById("categorie").value;
    const sousCategorieSelect = document.getElementById("sous_categorie");

    // Vide les anciennes options
    sousCategorieSelect.innerHTML = '<option value="">-- Sélectionner une sous-catégorie --</option>';

    // Remplit selon la catégorie choisie
    if (sousCategoriesData[categorie]) {
        sousCategoriesData[categorie].forEach(sc => {
            const option = document.createElement("option");
            option.value = sc;
            option.textContent = sc.charAt(0).toUpperCase() + sc.slice(1);
            sousCategorieSelect.appendChild(option);
        });
    }
}

// Remplir automatiquement après rechargement POST
window.addEventListener('DOMContentLoaded', () => {
    updateSousCategorie();

    const selectedSousCategorie = document.body.dataset.sousCategorie;
    if (selectedSousCategorie) {
        document.getElementById("sous_categorie").value = selectedSousCategorie;
    }
});
