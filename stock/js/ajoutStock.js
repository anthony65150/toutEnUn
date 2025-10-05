console.log("[Simpliz] ajoutStock.js chargé");

// --- helpers ---
const $  = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));
const show = (el) => { if (el) el.style.display = "block"; };
const hide = (el) => { if (el) el.style.display = "none"; };

const capitalizeFirst = (s = "") => s ? s.charAt(0).toUpperCase() + s.slice(1) : s;
// normalise pour tri/dédup insensibles aux accents/majuscules
const norm = (s="") => s.toString().toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").trim();

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

// --- Affichage / masquage champs "nouvelle sous-catégorie" ---
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

  const form                = $('form[action=""]') || $('form');
  const nomInput            = $('#nom');
  const quantiteInput       = $('#quantite');
  const quantiteLabel       = document.querySelector('label[for="quantite"]');

  // Nouveaux champs (ajoutés dans le HTML)
  const radioAnonyme        = $('#mode_anonyme');
  const radioNominatif      = $('#mode_nominatif');
  const maintenanceSelect   = $('#maintenance_mode'); // pas utilisé ici mais prêt si besoin

  // Aperçu sous le champ quantité (créé si absent)
  let preview = $('#nominatifPreview');
  if (!preview && quantiteInput) {
    preview = document.createElement('div');
    preview.id = 'nominatifPreview';
    preview.className = 'form-text mt-1';
    quantiteInput.parentElement?.appendChild(preview);
  }

  function getGestionMode() {
    // défaut = anonyme si les radios n'existent pas
    if (radioNominatif?.checked) return 'nominatif';
    return 'anonyme';
  }

  function syncQuantiteLabel() {
    if (!quantiteLabel) return;
    quantiteLabel.textContent = (getGestionMode() === 'nominatif')
      ? 'Nombre d’unités à créer'
      : 'Quantité totale';
    renderPreview();
  }

  function renderPreview() {
    if (!preview) return;
    const mode = getGestionMode();
    const base = (nomInput?.value || '').trim();
    const n    = Math.max(0, parseInt(quantiteInput?.value || '0', 10) || 0);

    if (mode === 'nominatif' && base && n > 0) {
      const maxShow = Math.min(n, 6);
      const parts = [];
      for (let i = 1; i <= maxShow; i++) parts.push(`${base} ${i}`);
      const more = (n > maxShow) ? ` … (+${n - maxShow} autres)` : '';
      preview.textContent = `Cela va créer ${n} lignes : ${parts.join(', ')}${more}`;
    } else if (mode === 'anonyme' && n > 0) {
      preview.textContent = `Une seule ligne sera créée avec la quantité ${n}.`;
    } else {
      preview.textContent = '';
    }
  }

  // ====== Catégorie / Sous-catégorie (ton code existant) ======
  const categorieSelect     = $('#categorieSelect');
  const sousCategorieSelect = $('#sous_categorieSelect');
  const newSousCatDiv       = $('#newSousCategorieDiv');
  const newSousCatInput     = $('#nouvelleSousCategorie');

  if (!categorieSelect || !sousCategorieSelect) {
    console.warn("[Simpliz] Elements de catégorie/sous-catégorie non trouvés.");
  } else {
    // état de requête pour annuler la précédente si nécessaire
    let currentAbort = null;

    function setSousCatLoading(loading) {
      if (loading) {
        sousCategorieSelect.innerHTML = '<option value="" disabled selected>Chargement…</option>';
        sousCategorieSelect.disabled = true;
      } else {
        sousCategorieSelect.disabled = false;
      }
    }

    // Changement de catégorie → maj sous-catégories + reset champ custom
    categorieSelect.addEventListener('change', () => {
      const categorie = categorieSelect.value;

      // Reset sous-catégorie
      sousCategorieSelect.innerHTML = '<option value="" disabled selected>-- Sélectionner une sous-catégorie --</option>';
      hide(newSousCatDiv);
      if (newSousCatInput) newSousCatInput.value = '';

      // Annule une requête en cours si on rechangera de catégorie rapidement
      if (currentAbort) {
        currentAbort.abort();
        currentAbort = null;
      }

      if (!categorie) return;

      setSousCatLoading(true);

      // IMPORTANT: URL relative au document (page et script dans /stock/)
      const url = `ajoutStock.php?action=getSousCategories&categorie=${encodeURIComponent(categorie)}`;
      currentAbort = new AbortController();

      fetch(url, { headers: { 'Accept': 'application/json' }, signal: currentAbort.signal })
        .then(res => {
          if (!res.ok) throw new Error(`HTTP ${res.status}`);
          return res.json();
        })
        .then(data => {
          // si on a été annulé entre-temps, on ne touche plus au DOM
          if (!categorieSelect.value) return;

          // data attendu: string[] ; on déduplique / trie joliment
          const unique = Array.isArray(data)
            ? Array.from(
                new Map(
                  data
                    .filter(Boolean)
                    .map(s => s.toString())
                    .map(s => [norm(s), s])
                ).values()
              )
            : [];

          unique.sort((a, b) => norm(a).localeCompare(norm(b)));

          // Vide → on garde juste l’option par défaut
          if (unique.length === 0) {
            sousCategorieSelect.innerHTML =
              '<option value="" disabled selected>Aucune sous-catégorie</option>';
            return;
          }

          // Remplit la liste
          sousCategorieSelect.innerHTML =
            '<option value="" disabled selected>-- Sélectionner une sous-catégorie --</option>';
          unique.forEach(subCat => {
            const option = document.createElement('option');
            option.value = subCat;
            option.textContent = capitalizeFirst(subCat);
            sousCategorieSelect.appendChild(option);
          });
        })
        .catch(err => {
          if (err.name === 'AbortError') return; // normal
          console.error('[Simpliz] Erreur fetch sous-catégories:', err);
          sousCategorieSelect.innerHTML =
            '<option value="" disabled selected>Impossible de charger les sous-catégories</option>';
        })
        .finally(() => {
          setSousCatLoading(false);
          currentAbort = null;
        });
    });

    // Déclencher la maj au chargement si une catégorie est déjà pré-sélectionnée
    if (categorieSelect.value) {
      categorieSelect.dispatchEvent(new Event('change'));
    }
  }

  // ====== Écouteurs pour le mode de gestion / aperçu ======
  [radioAnonyme, radioNominatif].forEach(r => r && r.addEventListener('change', syncQuantiteLabel));
  [nomInput, quantiteInput].forEach(i => i && i.addEventListener('input', renderPreview));

  // Confirmation si création nominative massive
  form && form.addEventListener('submit', (e) => {
    const base = (nomInput?.value || '').trim();
    const n    = Math.max(0, parseInt(quantiteInput?.value || '0', 10) || 0);
    if (!base) return;

    if (getGestionMode() === 'nominatif' && n > 50) {
      const ok = confirm(`Confirmer la création de ${n} lignes nominatives (“${base} 1..${n}”) ?`);
      if (!ok) e.preventDefault();
    }
  });

  // init
  syncQuantiteLabel();
  renderPreview();
});

// --- Expose global functions si utilisées par le HTML ---
window.showNewCategoryInput      = showNewCategoryInput;
window.toggleNewCategoryInput    = toggleNewCategoryInput;
window.showNewSubCategoryInput   = showNewSubCategoryInput;
window.toggleNewSubCategoryInput = toggleNewSubCategoryInput;
