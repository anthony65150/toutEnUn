document.addEventListener('DOMContentLoaded', () => {
  const transferModalEl = document.getElementById('transferModal');
  const modifyModalEl = document.getElementById('modifyModal');
  const deleteModalEl = document.getElementById('confirmDeleteModal');

  const transferModal = new bootstrap.Modal(transferModalEl);
  const modifyModal = new bootstrap.Modal(modifyModalEl);
  const deleteModal = new bootstrap.Modal(deleteModalEl);

  const confirmTransferButton = document.getElementById('confirmTransfer');
  const confirmModifyButton = document.getElementById('confirmModify');
  const confirmDeleteButton = document.getElementById('confirmDeleteButton');
  const sourceSelect = document.getElementById('sourceChantier');
  const destinationSelect = document.getElementById('destinationChantier');
  const qtyInput = document.getElementById('transferQty');
  const modalStockIdInput = document.getElementById('modalStockId');
  const deleteItemName = document.getElementById('deleteItemName');

  let currentRow = null;
  let currentDeleteId = null;
  let currentRowToDelete = null;

  

  function showToast(id) {
    const toastEl = document.getElementById(id);
    if (toastEl) {
      const toast = new bootstrap.Toast(toastEl);
      toast.show();
    }
  }

  function showErrorToast(message) {
    const errorMsg = document.getElementById('errorToastMessage');
    if(errorMsg) errorMsg.textContent = message;
    showToast('errorToast');
  }

  // ---------- TRANSFERT ----------
  confirmTransferButton.addEventListener('click', () => {
    const sourceValue = sourceSelect.value;
    const destinationValue = destinationSelect.value;
    const qty = parseInt(qtyInput.value, 10);
    const stockId = modalStockIdInput.value;

    if (!sourceValue || !destinationValue || isNaN(qty) || qty < 1) {
      showErrorToast('Veuillez remplir tous les champs.');
      return;
    }

    const [sourceType, sourceId] = sourceValue.split('_');
    const [destinationType, destinationId] = destinationValue.split('_');

    if (sourceType === destinationType && sourceId === destinationId) {
      showErrorToast('Source et destination doivent être différentes.');
      return;
    }

    const payload = {
      stockId,
      sourceType,
      sourceId: parseInt(sourceId, 10),
      destinationType,
      destinationId: parseInt(destinationId, 10),
      qty
    };

    fetch('transferStock_admin.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
      if (!data.success) {
        showErrorToast(data.message || 'Erreur lors du transfert.');
      } else {
        transferModal.hide();
        showToast('transferToast');
      }
    })
    .catch(err => {
      console.error(err);
      showErrorToast('Erreur réseau ou serveur.');
    });
  });

  document.querySelectorAll('.transfer-btn').forEach(button => {
    button.addEventListener('click', () => {
      modalStockIdInput.value = button.getAttribute('data-stock-id');
      qtyInput.value = '';
      sourceSelect.value = '';
      destinationSelect.value = '';
      transferModal.show();
    });
  });

  // ---------- MODIFIER ----------
  document.querySelectorAll('.edit-btn').forEach(button => {
    button.addEventListener('click', () => {
      const stockId = button.getAttribute('data-stock-id');
      const stockNom = button.getAttribute('data-stock-nom');
      currentRow = button.closest('tr');

      document.getElementById('modifyStockId').value = stockId;
      document.getElementById('modifyNom').value = stockNom;

      const quantiteCellText = currentRow.querySelector('td:first-child a').textContent;
      const quantiteMatch = quantiteCellText.match(/\((\d+)\)$/);
      if (quantiteMatch) {
        document.getElementById('modifyQty').value = quantiteMatch[1];
      } else {
        document.getElementById('modifyQty').value = '';
      }

      document.getElementById('modifyPhoto').value = '';

      modifyModal.show();
    });
  });

  confirmModifyButton.addEventListener('click', () => {
    const stockId = document.getElementById('modifyStockId').value;
    const nom = document.getElementById('modifyNom').value.trim();
    const quantite = document.getElementById('modifyQty').value;
    const photoFile = document.getElementById('modifyPhoto').files[0];

    if (!nom || (quantite && isNaN(quantite))) {
      showErrorToast('Veuillez remplir tous les champs correctement.');
      return;
    }

    const formData = new FormData();
    formData.append('stockId', stockId);
    formData.append('nom', nom);
    if(quantite) formData.append('quantite', quantite);
    if(photoFile) formData.append('photo', photoFile);

    fetch('modifierStock.php', {
      method: 'POST',
      body: formData
    })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        currentRow.querySelector('td:first-child a').textContent = `${data.newNom} (${data.newQuantiteTotale})`;
        if (data.newPhotoUrl) {
          const img = currentRow.querySelector('td:nth-child(2) img');
          if (img) {
            img.src = data.newPhotoUrl + '?t=' + Date.now();
          }
        }
        modifyModal.hide();
        showToast('modifyToast');
      } else {
        showErrorToast(data.message || 'Erreur lors de la modification.');
      }
    })
    .catch(err => {
      console.error(err);
      showErrorToast('Erreur réseau.');
    });
  });

  // ---------- SUPPRIMER ----------
  document.querySelectorAll('.delete-btn').forEach(button => {
    button.addEventListener('click', () => {
      currentDeleteId = button.getAttribute('data-stock-id');
      deleteItemName.textContent = button.getAttribute('data-stock-nom');
      currentRowToDelete = button.closest('tr');
      deleteModal.show();
    });
  });

  confirmDeleteButton.addEventListener('click', () => {
    if (!currentDeleteId) return;

    fetch(`supprimerStock.php?id=${currentDeleteId}`)
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          deleteModal.hide();
          if (currentRowToDelete) {
            currentRowToDelete.remove();
          }
          showToast('deleteToast');
        } else {
          alert(data.message || "Erreur lors de la suppression.");
        }
      })
      .catch(() => {
        alert("Erreur réseau ou serveur.");
      });
  });
});

document.addEventListener('DOMContentLoaded', () => {
  const subCatContainer = document.getElementById('subCategoriesSlide');
  if (!subCatContainer) return;

  // Vider avant d'ajouter
  subCatContainer.innerHTML = '';

  // Créer un bouton par sous-catégorie
  Object.entries(subCategories).forEach(([categorie, subCats]) => {
    subCats.forEach(subCat => {
      const btn = document.createElement('button');
      btn.classList.add('btn', 'btn-outline-secondary');
      btn.textContent = subCat.charAt(0).toUpperCase() + subCat.slice(1);
      btn.addEventListener('click', () => filterBySubCategory(subCat));
      subCatContainer.appendChild(btn);
    });
  });
});

function filterBySubCategory(subCat) {
  const rows = document.querySelectorAll('#stockTableBody tr');
  rows.forEach(row => {
    if (!subCat || row.dataset.subcat === subCat) {
      row.style.display = '';
    } else {
      row.style.display = 'none';
    }
  });
}

