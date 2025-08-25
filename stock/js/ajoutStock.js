console.log("[Simpliz] ajoutStock.js chargé");

// --- helpers ---
const $  = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));
const show = (el) => { if (el) el.style.display = "block"; };
const hide = (el) => { if (el) el.style.display = "none"; };

function capitalizeFirst(s = "") {
  return s ? s.charAt(0).toUpperCase() + s.slice(1) : s;
}

// --- Affichage / masquage champs "nouvelle catégorie" ---
function showNewCategoryInput() {
  const div = $('#newCategorieDiv');
  const input = $('#nouvelleCategorie');
  const select = $('#categorieSelect');
  show(div);
  input?.focus();
  if (select) select.value = "";
}

function toggleNewCategoryInput() {
  const select = $('#categorieSelect');
  const newCatDiv = $('#newCategorieDiv');
  if (select?.value) {
    hide(newCatDiv);
    const input = $('#nouvelleCategorie');
    if (input) input.value = '';
  }
}

// --- Affichage / masquage champs "nouvelle sous‑catégorie" ---
function showNewSubCategoryInput() {
  const div = $('#newSousCategorieDiv');
  const input = $('#nouvelleSousCategorie');
  const select = $('#sous_categorieSelect');
  show(div);
  input?.focus();
  if (select) select.value = "";
}

function toggleNewSubCategoryInput() {
  const select = $('#sous_categorieSelect');
  const newSubCatDiv = $('#newSousCategorieDiv');
  if (select?.value) {
    hide(newSubCatDiv);
    const input = $('#nouvelleSousCategorie');
    if (input) input.value = '';
  }
}

// --- DOM Ready ---
document.addEventListener('DOMContentLoaded', () => {
  // Déclenchement auto d’ouverture si drapeaux présents
  if (document.body.dataset.nouvelleCategorie)  showNewCategoryInput();
  if (document.body.dataset.nouvelleSousCategorie) showNewSubCategoryInput();

  const categorieSelect     = $('#categorieSelect');
  const sousCategorieSelect = $('#sous_categorieSelect');
  const newSousCatDiv       = $('#newSousCategorieDiv');
  const newSousCatInput     = $('#nouvelleSousCategorie');

  if (!categorieSelect || !sousCategorieSelect) {
    console.warn("[Simpliz] Elements de catégorie/sous-catégorie non trouvés.");
    return;
  }

  // Changement de catégorie → maj sous‑catégories + reset champ custom
  categorieSelect.addEventListener('change', () => {
    console.log('[Simpliz] Changement de catégorie détecté');
    const categorie = categorieSelect.value;

    // Reset sous-catégorie
    sousCategorieSelect.innerHTML = '<option value="" disabled selected>-- Sélectionner une sous-catégorie --</option>';
    hide(newSousCatDiv);
    if (newSousCatInput) newSousCatInput.value = '';

    if (!categorie) return;

    // IMPORTANT: URL relative au DOCUMENT. Comme la page et ajoutStock.php sont tous deux dans /stock/,
    // cet appel est correct avec la nouvelle arborescence.
    const url = `ajoutStock.php?action=getSousCategories&categorie=${encodeURIComponent(categorie)}`;

    fetch(url, { headers: { 'Accept': 'application/json' }})
      .then(res => {
        console.log('[Simpliz] Réponse fetch:', res.status, res.statusText);
        return res.json();
      })
      .then(data => {
        console.log('[Simpliz] Sous-catégories reçues:', data);
        if (Array.isArray(data)) {
          data.forEach(subCat => {
            const option = document.createElement('option');
            option.value = subCat;
            option.textContent = capitalizeFirst(subCat);
            sousCategorieSelect.appendChild(option);
          });
        } else {
          console.warn('[Simpliz] Format inattendu pour les sous-catégories.');
        }
      })
      .catch(err => {
        console.error('[Simpliz] Erreur fetch:', err);
      });
  });

  // Déclencher la maj au chargement si une catégorie est déjà pré‑sélectionnée
  if (categorieSelect.value) {
    categorieSelect.dispatchEvent(new Event('change'));
  }
});

// --- Expose global functions si utilisées par le HTML ---
window.showNewCategoryInput     = showNewCategoryInput;
window.toggleNewCategoryInput   = toggleNewCategoryInput;
window.showNewSubCategoryInput  = showNewSubCategoryInput;
window.toggleNewSubCategoryInput= toggleNewSubCategoryInput;
