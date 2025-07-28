document.addEventListener('DOMContentLoaded', () => {
  const transferModal = new bootstrap.Modal(document.getElementById('transferModal'));
  const modifyModal = new bootstrap.Modal(document.getElementById('modifyModal'));
  const deleteModal = new bootstrap.Modal(document.getElementById('confirmDeleteModal'));

  let currentRow = null;
  let currentDeleteId = null;

  // Toasts
  const showToast = (id) => {
    const toastEl = document.getElementById(id);
    if (toastEl) new bootstrap.Toast(toastEl).show();
  };

  const showErrorToast = (message) => {
    const errorMsg = document.getElementById('errorToastMessage');
    if (errorMsg) errorMsg.textContent = message;
    showToast('errorToast');
  };

  // ----- TRANSFERT -----
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
    const [sourceType, sourceId] = document.getElementById('sourceChantier').value.split('_');
    const [destinationType, destinationId] = document.getElementById('destinationChantier').value.split('_');
    const qty = parseInt(document.getElementById('transferQty').value, 10);

    if (!sourceType || !destinationType || isNaN(qty) || qty < 1) {
      showErrorToast('Veuillez remplir tous les champs.');
      return;
    }
    if (sourceType === destinationType && sourceId === destinationId) {
      showErrorToast('Source et destination doivent être différentes.');
      return;
    }

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

    // ⚡ Mettre à jour la quantité à la source sans recharger
    const qtyCell = document.querySelector(`#qty-source-${sourceType}-${sourceId}-${stockId}`);
    if (qtyCell) {
      const currentQty = parseInt(qtyCell.textContent, 10);
      const newQty = currentQty - qty;
      qtyCell.textContent = newQty >= 0 ? newQty : 0;
    }

  } else {
    showErrorToast(data.message);
  }
})

    .catch(() => showErrorToast('Erreur réseau ou serveur.'));
  });

// ----- MODIFIER -----
document.getElementById('stockTableBody').addEventListener('click', (e) => {
  const btn = e.target.closest('.edit-btn');
  if (!btn) return;

  currentRow = btn.closest('tr');

  document.getElementById('modifyStockId').value = btn.dataset.stockId;
  document.getElementById('modifyNom').value = btn.dataset.stockNom;
  document.getElementById('modifyQty').value = btn.dataset.stockQuantite;

  document.getElementById('modifyPhoto').value = '';
  document.getElementById('modifierDocument').value = '';

  const photoDiv = document.getElementById('existingPhoto');
  if (btn.dataset.stockPhoto) {
    photoDiv.innerHTML = `<img src="uploads/${btn.dataset.stockPhoto}" alt="Photo actuelle" class="img-thumbnail" style="max-height: 100px;">`;
  } else {
    photoDiv.innerHTML = '<em class="text-muted">Aucune photo</em>';
  }

const docDiv = document.getElementById('existingDocument');
if (btn.dataset.stockDocument) {
  docDiv.innerHTML = `
    <div class="d-flex align-items-center gap-2">
      <a href="uploads/documents/${btn.dataset.stockDocument}" target="_blank" class="text-info">📄 Voir le document</a>
      <button type="button" class="btn btn-sm btn-outline-danger p-1" id="btnRemoveDocument" title="Supprimer le document">
        <i class="bi bi-trash3"></i>
      </button>
      <input type="hidden" name="deleteDocument" id="deleteDocumentHidden" value="0">
    </div>
  `;

  // 🔁 Bien récupérer les éléments APRES avoir injecté le HTML
  const deleteBtn = document.getElementById('btnRemoveDocument');
  const deleteHidden = document.getElementById('deleteDocumentHidden');

  if (deleteBtn && deleteHidden) {
    deleteBtn.addEventListener('click', () => {
      deleteHidden.value = '1';

      // Supprimer uniquement le lien et le bouton
      const link = docDiv.querySelector('a');
      if (link) link.remove();
      deleteBtn.remove();

      // Conserver le champ hidden et ajouter le texte
      docDiv.insertAdjacentHTML('beforeend', '<em class="text-muted">Document marqué pour suppression</em>');
    });
  }
} else {
  docDiv.innerHTML = '<em class="text-muted">Aucun document</em>';
}


  modifyModal.show();
});



  document.getElementById('confirmModify').addEventListener('click', () => {
  const formData = new FormData();
  formData.append('stockId', document.getElementById('modifyStockId').value);
  formData.append('nom', document.getElementById('modifyNom').value);
  formData.append('quantite', document.getElementById('modifyQty').value);

  const photoFile = document.getElementById('modifyPhoto').files[0];
  if (photoFile) formData.append('photo', photoFile);

  const documentFile = document.getElementById('modifierDocument')?.files[0];
  if (documentFile) formData.append('document', documentFile);

const hiddenDeleteInput = document.getElementById('deleteDocumentHidden');
const deleteDoc = hiddenDeleteInput?.value === '1';
formData.append('deleteDocument', deleteDoc ? '1' : '0');



  fetch('modifierStock.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        const editBtn = currentRow.querySelector('.edit-btn');
        if (editBtn) {
          editBtn.dataset.stockNom = data.newNom;
          editBtn.dataset.stockQuantite = data.newQuantiteTotale;
          if (documentFile || deleteDoc) {
            editBtn.dataset.stockDocument = data.newDocument || '';
          }
        }

        // Mise à jour nom
        currentRow.querySelector('td:first-child').innerHTML = `
          <a href="article.php?id=${encodeURIComponent(data.newNom)}" class="nom-article text-decoration-underline fw-bold text-primary">
            ${data.newNom.charAt(0).toUpperCase() + data.newNom.slice(1).toLowerCase()}
          </a> (${data.newQuantiteTotale})
        `;

        // Mise à jour badge dépôt 1
        const depotDivs = currentRow.querySelectorAll('td:nth-child(3) div');
        depotDivs.forEach(div => {
          if (div.textContent.includes('1')) {
            const badge = div.querySelector('span');
            if (badge) {
              badge.textContent = `(${data.quantiteDispo})`;
              badge.className = `badge ${data.quantiteDispo < 10 ? 'bg-danger' : 'bg-success'}`;
            }
          }
        });

        // Mise à jour image si changée
        const img = currentRow.querySelector('td:nth-child(2) img');
        if (img && data.newPhotoUrl) {
          img.src = data.newPhotoUrl + '?t=' + Date.now();
        }

        modifyModal.hide();
        currentRow.classList.add("table-success");
        showToast('modifyToast');
        setTimeout(() => currentRow.classList.remove("table-success"), 3000);
      } else {
        showErrorToast(data.message);
      }
    })
    .catch(() => showErrorToast('Erreur réseau.'));
});



  // ----- SUPPRIMER -----
  document.querySelectorAll('.delete-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      currentDeleteId = btn.dataset.stockId;
      document.getElementById('deleteItemName').textContent = btn.dataset.stockNom;
      currentRow = btn.closest('tr');
      deleteModal.show();
    });
  });

  document.getElementById('confirmDeleteButton').addEventListener('click', () => {
    fetch(`supprimerStock.php?id=${currentDeleteId}`)
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        if (currentRow) currentRow.remove();
        deleteModal.hide();
        showToast('modifyToast');
      } else {
        showErrorToast(data.message);
      }
    })
    .catch(() => showErrorToast('Erreur réseau.'));
  });

  // ----- FILTRAGE -----
  const searchInput = document.getElementById("searchInput");
  const tableRows = document.querySelectorAll("#stockTableBody tr");
  const subCategoriesSlide = document.getElementById("subCategoriesSlide");
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
    const subs = subCategories[cat] || [];
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
      const rowCat = row.dataset.cat;
      const rowSub = row.dataset.subcat;
      const matchCat = !currentCategory || rowCat === currentCategory;
      const matchSub = !subCat || rowSub === subCat;
      row.style.display = (matchCat && matchSub) ? "" : "none";
    });
  }

  searchInput.addEventListener("input", () => {
    const search = searchInput.value.toLowerCase();
    tableRows.forEach(row => {
      const name = row.querySelector("td:first-child").textContent.toLowerCase();
      row.style.display = name.includes(search) ? "" : "none";
    });
  });

  filterByCategory('');
});


  // ----- VALIDATION DES TRANSFERTS -----
  document.querySelectorAll('.btn-valider-transfert').forEach(btn => {
    btn.addEventListener('click', () => {
      const transfertId = btn.dataset.transfertId;

      fetch('validerReception_admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `transfert_id=${encodeURIComponent(transfertId)}`
      })
      .then(res => {
        if (!res.ok) throw new Error("Erreur serveur");
        return res.text(); // car tu rediriges avec header() dans PHP
      })
         .then(() => {
      const row = document.querySelector(`tr[data-row-id="${transfertId}"]`);
      if (row) {
        row.classList.add("table-success");
        showToast('modifyToast');
        setTimeout(() => location.reload(), 1000);
      } else {
        location.reload();
      }
    })

      .catch(err => {
        console.error(err);
        showErrorToast('Erreur lors de la validation du transfert.');
      });
    });
  });

