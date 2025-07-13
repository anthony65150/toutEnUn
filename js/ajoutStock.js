console.log('ajoutStock.js chargé');

function showNewCategoryInput() {
    document.getElementById('newCategorieDiv').style.display = 'block';
    document.getElementById('nouvelleCategorie').focus();
    document.getElementById('categorieSelect').value = '';
}

function toggleNewCategoryInput() {
    const select = document.getElementById('categorieSelect');
    const newCatDiv = document.getElementById('newCategorieDiv');
    if (select.value) {
        newCatDiv.style.display = 'none';
        document.getElementById('nouvelleCategorie').value = '';
    }
}

function showNewSubCategoryInput() {
    document.getElementById('newSousCategorieDiv').style.display = 'block';
    document.getElementById('nouvelleSousCategorie').focus();
    document.getElementById('sous_categorieSelect').value = '';
}

function toggleNewSubCategoryInput() {
    const select = document.getElementById('sous_categorieSelect');
    const newSubCatDiv = document.getElementById('newSousCategorieDiv');
    if (select.value) {
        newSubCatDiv.style.display = 'none';
        document.getElementById('nouvelleSousCategorie').value = '';
    }
}

// Assure que le DOM est prêt avant d’attacher les événements
window.addEventListener('DOMContentLoaded', () => {
    if (document.body.dataset.nouvelleCategorie) {
        showNewCategoryInput();
    }
    if (document.body.dataset.nouvelleSousCategorie) {
        showNewSubCategoryInput();
    }

    const categorieSelect = document.getElementById('categorieSelect');
    if (categorieSelect) {
        categorieSelect.addEventListener('change', () => {
            console.log('Changement catégorie détecté');
            const categorie = categorieSelect.value;
            const sousCategorieSelect = document.getElementById('sous_categorieSelect');

            // Reset sous-catégorie
            sousCategorieSelect.innerHTML = '<option value="" disabled selected>-- Sélectionner une sous-catégorie --</option>';
            document.getElementById('newSousCategorieDiv').style.display = 'none';
            document.getElementById('nouvelleSousCategorie').value = '';

            if (!categorie) return;

            fetch(`ajoutStock.php?action=getSousCategories&categorie=${encodeURIComponent(categorie)}`)
                .then(res => {
                    console.log('Réponse fetch:', res);
                    return res.json();
                })
                .then(data => {
                    console.log('Sous-catégories reçues:', data);
                    if (Array.isArray(data)) {
                        data.forEach(subCat => {
                            const option = document.createElement('option');
                            option.value = subCat;
                            option.textContent = subCat.charAt(0).toUpperCase() + subCat.slice(1);
                            sousCategorieSelect.appendChild(option);
                        });
                    }
                })
                .catch(err => {
                    console.error('Erreur fetch:', err);
                });
        });

        // Pour déclencher la mise à jour au chargement si une catégorie est déjà sélectionnée
        if (categorieSelect.value) {
            categorieSelect.dispatchEvent(new Event('change'));
        }
    } else {
        console.warn('Element categorieSelect introuvable dans le DOM');
    }
});
