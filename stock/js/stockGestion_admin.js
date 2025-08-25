document.addEventListener('DOMContentLoaded', () => {
  // --- Utils ---
  // IMPORTANT: on ne force plus de "/" au début : on respecte ../, ./, http(s), data:
  const asWebPath = (p) => {
    if (!p) return '';
    if (p.startsWith('../') || p.startsWith('./') || p.startsWith('http') || p.startsWith('data:') || p.startsWith('/')) {
      return p;
    }
    // Si ta BDD stocke "uploads/..." sans slash, depuis /stock/ il faut remonter d'un cran :
    return '../' + p;
  };

  // --- Modals ---
  const transferModal = new bootstrap.Modal(document.getElementById('transferModal'));
  const modifyModal   = new bootstrap.Modal(document.getElementById('modifyModal'));
  const deleteModal   = new bootstrap.Modal(document.getElementById('confirmDeleteModal'));

  function attachRowHandlers(row) {
    // Transfer
    const tBtn = row.querySelector('.transfer-btn');
    if (tBtn) {
      tBtn.addEventListener('click', () => {
        document.getElementById('modalStockId').value = tBtn.dataset.stockId;
        document.getElementById('sourceChantier').value = '';
        document.getElementById('destinationChantier').value = '';
        document.getElementById('transferQty').value = '';
        transferModal.show();
      });
    }

    // Delete
    const dBtn = row.querySelector('.delete-btn');
    if (dBtn) {
      dBtn.addEventListener('click', () => {
        currentDeleteId = dBtn.dataset.stockId;
        document.getElementById('deleteItemName').textContent = dBtn.dataset.stockNom;
        currentRow = row;
        deleteModal.show();
      });
    }
  }

  // Force l'alignement attendu (optionnel)
  function normalizeRowAlignment(row) {
    if (!row) return;
    const tds = row.querySelectorAll('td');
    if (!tds.length) return;
    tds.forEach((td, i) => {
      td.classList.remove('text-center', 'text-start');
      if (i === 0 || i === tds.length - 1) td.classList.add('text-center');
      else td.classList.add('text-start');
    });
  }
  function normalizeTable() {
    document.querySelectorAll('#stockTableBody > tr').forEach(normalizeRowAlignment);
  }

  let currentRow = null;
  let currentDeleteId = null;

  // Track docs à supprimer (par ID) pendant que la modale est ouverte
  let docsToDelete = new Set();

  // --- Toasts ---
  const showToast = (id) => {
    const toastEl = document.getElementById(id);
    if (toastEl) new bootstrap.Toast(toastEl).show();
  };
  const showErrorToast = (message) => {
    const errorMsg = document.getElementById('errorToastMessage');
    if (errorMsg) errorMsg.textContent = message;
    showToast('errorToast');
  };

  // =========================================================
  // TRANSFERT
  // =========================================================
  document.querySelectorAll('.transfer-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.getElementById('modalStockId').value = btn.dataset.stockId;
      document.getElementById('sourceChantier').value = '';
      document.getElementById('destinationChantier').value = '';
      document.getElementById('transferQty').value = '';
      transferModal.show();
    });
  });

  document.getElementById('confirmTransfer').addEventListener('click', () => {
    const stockId = document.getElementById('modalStockId').value;
    const [sourceType, sourceId] = (document.getElementById('sourceChantier').value || '').split('_');
    const [destinationType, destinationId] = (document.getElementById('destinationChantier').value || '').split('_');
    const qty = parseInt(document.getElementById('transferQty').value, 10);

    if (!sourceType || !destinationType || isNaN(qty) || qty < 1) {
      showErrorToast('Veuillez remplir tous les champs.');
      return;
    }
    if (sourceType === destinationType && sourceId === destinationId) {
      showErrorToast('Source et destination doivent être différentes.');
      return;
    }

    // Endpoints dans /stock/
    fetch('transferStock_admin.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ stockId, sourceType, sourceId, destinationType, destinationId, qty })
    })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        transferModal.hide();
        showToast('modifyToast');

        // MAJ visuelle rapide côté "chantier" (si c'est la source)
        const qtyCell = document.querySelector(`#qty-source-${sourceType}-${sourceId}-${stockId}`);
        if (qtyCell) {
          const currentQty = parseInt(qtyCell.textContent, 10);
          qtyCell.textContent = Math.max(0, currentQty - qty);
        }
      } else {
        showErrorToast(data.message || 'Erreur lors du transfert.');
      }
    })
    .catch(() => showErrorToast('Erreur réseau ou serveur.'));
  });

  // =========================================================
  // MODIFIER : helpers Documents (multi)
  // =========================================================
  const renderExistingDocs = (docsArr) => {
    const docsDiv = document.getElementById('existingDocs');
    docsDiv.innerHTML = '';

    if (!Array.isArray(docsArr) || docsArr.length === 0) {
      docsDiv.innerHTML = '<em class="text-muted">Aucun document</em>';
      return;
    }

    docsArr.forEach(d => {
      const line = document.createElement('div');
      line.className = 'd-flex align-items-center justify-content-between border rounded p-2';
      line.dataset.docId = d.id;

      line.innerHTML = `
        <div class="d-flex flex-column">
          <a href="${asWebPath(d.url)}" target="_blank" rel="noopener">${d.nom}</a>
          ${d.size ? `<small class="text-muted">${(d.size/1024).toFixed(1)} Ko</small>` : ``}
        </div>
        <button type="button" class="btn btn-sm btn-outline-danger p-1 doc-toggle-delete" title="Marquer pour suppression">
          <i class="bi bi-trash3"></i>
        </button>
      `;

      docsDiv.appendChild(line);
    });
  };

  const markDocLine = (lineEl, marked) => {
    if (!lineEl) return;
    lineEl.classList.toggle('opacity-50', marked);
    lineEl.classList.toggle('text-decoration-line-through', marked);
  };

  const loadExistingDocs = (stockId) => {
    const docsDiv = document.getElementById('existingDocs');
    docsDiv.innerHTML = '<span class="text-muted">Chargement…</span>';

    // Endpoint dans /stock/
    return fetch(`article_documents.php?id=${encodeURIComponent(stockId)}`)
      .then(r => r.json())
      .then(renderExistingDocs)
      .catch(() => {
        docsDiv.innerHTML = '<em class="text-muted">Impossible de charger les documents</em>';
      });
  };

  // =========================================================
  // OUVRIR MODIFIER
  // =========================================================
  document.getElementById('stockTableBody').addEventListener('click', (e) => {
    const btn = e.target.closest('.edit-btn');
    if (!btn) return;

    currentRow = btn.closest('tr');

    // Champs modale
    const idInput           = document.getElementById('modifyStockId');
    const nomInput          = document.getElementById('modifyNom');
    const qtyInput          = document.getElementById('modifyQty');
    const filePhotoInput    = document.getElementById('modifyPhoto');
    const delPhotoInput     = document.getElementById('deletePhoto'); // hidden global
    const photoDiv          = document.getElementById('existingPhoto');

    // Multi-docs elements
    const fileDocsInput     = document.getElementById('modifierDocument'); // name="documents[]" multiple
    const newDocsPreview    = document.getElementById('newDocsPreview');
    const deleteDocIdsInput = document.getElementById('deleteDocIds');     // hidden JSON "[]"

    docsToDelete = new Set();
    deleteDocIdsInput.value = '[]';

    idInput.value  = btn.dataset.stockId;
    nomInput.value = btn.dataset.stockNom || '';
    qtyInput.value = btn.dataset.stockQuantite || '';

    filePhotoInput.value = '';
    delPhotoInput.value  = '0';

    fileDocsInput.value  = '';
    newDocsPreview.innerHTML = '';

    // PHOTO EXISTANTE
    photoDiv.innerHTML = '';
    if (btn.dataset.stockPhoto) {
      const src = asWebPath(btn.dataset.stockPhoto);
      photoDiv.innerHTML = `
        <div class="d-flex align-items-center gap-2">
          <img src="${src}" alt="Photo actuelle" class="img-thumbnail" style="max-height: 100px;">
          <button type="button" class="btn btn-sm btn-outline-danger p-1" id="btnRemovePhoto" title="Supprimer la photo">
            <i class="bi bi-trash3"></i>
          </button>
        </div>
        <small class="text-muted d-block">La suppression sera appliquée à l’enregistrement.</small>
      `;
      const deleteBtn = document.getElementById('btnRemovePhoto');
      if (deleteBtn) {
        deleteBtn.addEventListener('click', () => {
          delPhotoInput.value = '1';
          const img = photoDiv.querySelector('img');
          if (img) img.remove();
          deleteBtn.remove();
          photoDiv.insertAdjacentHTML('beforeend', '<em class="text-muted">Photo prête à être supprimée</em>');
        });
      }
    } else {
      photoDiv.innerHTML = '<em class="text-muted">Aucune photo</em>';
    }
    filePhotoInput.addEventListener('change', () => {
      if (delPhotoInput.value === '1') delPhotoInput.value = '0';
    }, { once: true });

    // DOCS EXISTANTS
    loadExistingDocs(idInput.value).then(() => {});

    // NOUVEAUX DOCS : preview + retrait
    fileDocsInput.onchange = () => {
      newDocsPreview.innerHTML = '';
      const files = Array.from(fileDocsInput.files);
      files.forEach((f, idx) => {
        const row = document.createElement('div');
        row.className = 'd-flex align-items-center justify-content-between border rounded p-2';
        row.innerHTML = `
          <div>${f.name} <small class="text-muted">(${Math.round(f.size/1024)} Ko)</small></div>
          <button type="button" class="btn btn-sm btn-outline-secondary btnRemoveNewDoc" data-index="${idx}">
            Retirer
          </button>
        `;
        newDocsPreview.appendChild(row);
      });

      newDocsPreview.querySelectorAll('.btnRemoveNewDoc').forEach(btnRm => {
        btnRm.addEventListener('click', () => {
          const removeIdx = Number(btnRm.dataset.index);
          const dt = new DataTransfer();
          Array.from(fileDocsInput.files).forEach((file, i) => {
            if (i !== removeIdx) dt.items.add(file);
          });
          fileDocsInput.files = dt.files;
          btnRm.closest('.d-flex').remove();
          [...newDocsPreview.querySelectorAll('.btnRemoveNewDoc')].forEach((b, i) => b.dataset.index = String(i));
        });
      });
    };

    // Ouvrir la modale
    modifyModal.show();
  });

  // =========================================================
  // DOCUMENTS : marquage pour suppression en lot
  // =========================================================
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.doc-toggle-delete');
    if (!btn) return;

    const line = btn.closest('[data-doc-id]');
    const docId = line?.dataset.docId;
    if (!docId) return;

    if (docsToDelete.has(docId)) {
      docsToDelete.delete(docId);
      markDocLine(line, false);
      btn.title = 'Marquer pour suppression';
    } else {
      docsToDelete.add(docId);
      markDocLine(line, true);
      btn.title = 'Annuler la suppression';
    }
  });

  // =========================================================
  // ENREGISTRER MODIFS (submit)
  // =========================================================
  document.getElementById('modifyForm').addEventListener('submit', (e) => {
    e.preventDefault();

    const form = e.currentTarget;
    const submitBtn = document.getElementById('confirmModify');

    const deleteDocIdsInput = document.getElementById('deleteDocIds');
    deleteDocIdsInput.value = JSON.stringify(Array.from(docsToDelete).map(Number));

    const fd = new FormData(form); // stockId, nom, quantite, photo, documents[], deletePhoto, deleteDocIds

    const delPhotoInput = document.getElementById('deletePhoto');
    fd.set('deletePhoto', delPhotoInput ? delPhotoInput.value : '0');

    const nom = (fd.get('nom') || '').toString().trim();
    const qte = Number(fd.get('quantite'));
    if (!nom || Number.isNaN(qte) || qte < 0) {
      showErrorToast('Vérifie le nom et la quantité.');
      return;
    }

    submitBtn.disabled = true;

    // Endpoint dans /stock/
    fetch('modifierStock.php', { method: 'POST', body: fd })
      .then(res => res.json())
      .then(data => {
        if (!data.success) throw new Error(data.message || 'Erreur inconnue');

        // 1) Remplacement de ligne si le serveur renvoie rowHtml
        if (currentRow && data.rowHtml) {
          const tmp = document.createElement('tbody');
          tmp.innerHTML = data.rowHtml.trim();
          const newRow = tmp.querySelector('tr');

          currentRow.replaceWith(newRow);
          currentRow = newRow;
          attachRowHandlers(currentRow);

          currentRow.classList.add('table-success');
          setTimeout(() => currentRow.classList.remove('table-success'), 3000);
          modifyModal.hide();
          showToast('modifyToast');

          if (Array.isArray(data.docsAll)) renderExistingDocs(data.docsAll);
          return;
        }

        // 2) Fallback : MAJ manuelle
        if (currentRow) {
          const stockId = currentRow.dataset.rowId;

          // ARTICLE (nom + total)
          const articleCell = currentRow.querySelector('td.td-article');
          if (articleCell) {
            const newName = (data.newNom || '').toString();
            const capName = newName ? (newName.charAt(0).toUpperCase() + newName.slice(1)) : '';
            articleCell.innerHTML = `
              <a href="article.php?id=${encodeURIComponent(stockId)}"
                 class="fw-semibold text-decoration-none">${capName}</a>
              <span class="ms-1 text-muted">(${data.newQuantiteTotale})</span>
              <div class="small text-muted">${
                [currentRow.dataset.cat, currentRow.dataset.subcat].filter(Boolean).join(' • ') || '—'
              }</div>
            `;
          }

          // PHOTO (anti-cache)
          const imgEl = currentRow.querySelector('td.col-photo img.article-photo, td.col-photo img');
          if (imgEl) {
            if (data.newPhotoUrl) {
              const url = asWebPath(data.newPhotoUrl);
              imgEl.src = url + (url.includes('?') ? '&' : '?') + 't=' + Date.now();
              imgEl.classList.remove('d-none');
              imgEl.style.display = '';
            } else {
              imgEl.src = '';
              imgEl.classList.add('d-none');
              imgEl.style.display = 'none';
            }
          }

          // Dataset du bouton éditer
          const editBtn = currentRow.querySelector('.edit-btn');
          if (editBtn) {
            editBtn.dataset.stockNom      = data.newNom ?? editBtn.dataset.stockNom;
            editBtn.dataset.stockQuantite = data.newQuantiteTotale ?? editBtn.dataset.stockQuantite;
            editBtn.dataset.stockPhoto    = data.newPhotoUrl ? asWebPath(data.newPhotoUrl) : '';
          }

          currentRow.classList.add('table-success');
          setTimeout(() => currentRow.classList.remove('table-success'), 3000);
        }

        if (Array.isArray(data.docsAll)) renderExistingDocs(data.docsAll);

        modifyModal.hide();
        showToast('modifyToast');
      })
      .catch(err => {
        console.error(err);
        showErrorToast(err.message || 'Erreur lors de la modification.');
      })
      .finally(() => {
        submitBtn.disabled = false;
        const inputDocs = document.getElementById('modifierDocument');
        const newDocsPreview = document.getElementById('newDocsPreview');
        if (inputDocs) inputDocs.value = '';
        if (newDocsPreview) newDocsPreview.innerHTML = '';
        docsToDelete.clear();
        const deleteDocIdsInput2 = document.getElementById('deleteDocIds');
        if (deleteDocIdsInput2) deleteDocIdsInput2.value = '[]';
      });
  });

  // =========================================================
  // SUPPRIMER (article)
  // =========================================================
  document.querySelectorAll('.delete-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      currentDeleteId = btn.dataset.stockId;
      document.getElementById('deleteItemName').textContent = btn.dataset.stockNom;
      currentRow = btn.closest('tr');
      deleteModal.show();
    });
  });

  document.getElementById('confirmDeleteButton').addEventListener('click', () => {
    // Endpoint dans /stock/
    fetch(`supprimerStock.php?id=${currentDeleteId}`)
      .then(res => res.json())
      .then(() => location.reload())
      .catch(() => showErrorToast('Erreur lors de la suppression.'));
  });

  // =========================================================
  // FILTRES + RECHERCHE
  // =========================================================
  const subCategoriesSlide = document.getElementById("subCategoriesSlide");
  const searchInput = document.getElementById("searchInput");
  const tableRows = document.querySelectorAll("#stockTableBody tr");
  let currentCategory = "";

  window.filterByCategory = (cat) => {
    currentCategory = cat;
    document.querySelectorAll("#categoriesSlide button").forEach(b => b.classList.remove("active"));
    [...document.querySelectorAll("#categoriesSlide button")]
      .find(b => b.textContent.toLowerCase().includes(cat.toLowerCase()))
      ?.classList.add("active");

    updateSubCategories(cat);
    filterRows();
  };

  function updateSubCategories(cat) {
    subCategoriesSlide.innerHTML = '';
    const subs = (typeof subCategories !== 'undefined' && subCategories[cat]) ? subCategories[cat] : [];
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

  function filterRows(subCat = '') {
    tableRows.forEach(row => {
      const rowCat = row.dataset.cat || '';
      const rowSub = row.dataset.subcat || '';
      const matchCat = !currentCategory || rowCat === currentCategory;
      const matchSub = !subCat || rowSub === subCat;
      // Couplé avec la recherche (si active)
      const search = searchInput.value.toLowerCase();
      const nameCell = row.querySelector("td.td-article");
      const name = (nameCell ? nameCell.textContent : "").toLowerCase();
      const matchSearch = !search || name.includes(search);

      row.style.display = (matchCat && matchSub && matchSearch) ? "" : "none";
    });
  }

  searchInput.addEventListener("input", () => filterRows(
    (document.querySelector("#subCategoriesSlide button.active")?.textContent || '')
  ));

  filterByCategory('');
  normalizeTable(); // optionnel
});
