document.addEventListener('DOMContentLoaded', () => {
  const transferModal = document.getElementById('transferModal');
  const confirmButton = document.getElementById('confirmTransfer');
  const sourceSelect = document.getElementById('sourceChantier');
  const destinationSelect = document.getElementById('destinationChantier');
  const qtyInput = document.getElementById('transferQty');
  const modalStockIdInput = document.getElementById('modalStockId');

 function showToast(id) {
  const toastElement = document.getElementById(id);
  if (toastElement) {
    const toast = new bootstrap.Toast(toastElement);
    toast.show();
  }
}

function showErrorToast(message) {
  const msgElem = document.getElementById("errorToastMessage");
  if (msgElem) msgElem.textContent = message;
  showToast("errorToast");
}


  function sendTransfer(payload) {
    fetch('transferStock_admin.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
   if (!data.success) {
  showErrorToast(data.message || "Erreur lors du transfert.");
  return;
}

      const row = document.querySelector(`button[data-stock-id="${payload.stockId}"]`)?.closest("tr");
      if (row) {
        const qtyCell = row.querySelector(".quantite-col");
        if (qtyCell) {
          qtyCell.innerHTML = `
            <span class="badge bg-success">${data.disponible} dispo</span>
            <span class="badge bg-warning text-dark">${data.surChantier} sur chantier</span>
          `;
        }

        const adminCell = row.querySelector(".admin-col");
        if (adminCell && data.chantiersHtml) {
          adminCell.innerHTML = data.chantiersHtml;
        }
      }

      bootstrap.Modal.getInstance(transferModal)?.hide();
      showToast("transferToast");
    })
    .catch(error => {
      console.error("❌ Erreur réseau :", error);
      showErrorToast("Erreur réseau ou serveur.");
    });
  }

  confirmButton.addEventListener('click', () => {
    const source = sourceSelect.value;
    const destination = destinationSelect.value;
    const qty = parseInt(qtyInput.value, 10);
    const stockId = modalStockIdInput.value;

    if (!source || !destination || isNaN(qty) || qty < 1) {
      showErrorToast("Veuillez remplir tous les champs.");
      return;
    }

    if (source === destination) {
      showErrorToast("Source et destination doivent être différentes.");
      return;
    }

    const payload = { stockId, source, destination, qty };
    sendTransfer(payload);
  });

  document.querySelectorAll('.transfer-btn').forEach(button => {
    button.addEventListener('click', () => {
      modalStockIdInput.value = button.getAttribute('data-stock-id');
      qtyInput.value = '';
      sourceSelect.value = '';
      destinationSelect.value = '';
      new bootstrap.Modal(transferModal).show();
    });
  });
});

document.addEventListener('DOMContentLoaded', () => {
  const modifyModal = new bootstrap.Modal(document.getElementById('modifyModal'));
  const confirmModify = document.getElementById('confirmModify');
  let currentRow = null;

  document.querySelectorAll('.edit-btn').forEach(button => {
    button.addEventListener('click', () => {
      const stockId = button.getAttribute('data-stock-id');
      const stockNom = button.getAttribute('data-stock-nom');
      const stockQuantite = button.getAttribute('data-stock-quantite');

      document.getElementById('modifyStockId').value = stockId;
      document.getElementById('modifyNom').value = stockNom;
      document.getElementById('modifyQty').value = stockQuantite;
      currentRow = button.closest('tr');

      modifyModal.show();
    });
  });

  confirmModify.addEventListener('click', () => {
  const stockId = document.getElementById('modifyStockId').value;
  const nom = document.getElementById('modifyNom').value.trim();
  const quantite = parseInt(document.getElementById('modifyQty').value, 10);
  const photoInput = document.getElementById('modifyPhoto');
  const photoFile = photoInput.files[0];

  if (!nom || isNaN(quantite)) {
    showErrorToast('Veuillez remplir tous les champs correctement.');
    return;
  }

  const formData = new FormData();
  formData.append('stockId', stockId);
  formData.append('nom', nom);
  formData.append('quantite', quantite);
  if (photoFile) {
    formData.append('photo', photoFile);
  }

  fetch('modifierStock.php', {
    method: 'POST',
    body: formData
  })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        currentRow.querySelector('td:nth-child(1)').innerHTML = `<a href="article.php?id=${stockId}">${data.newNom} (${data.newTotal})</a>`;

        const badge = currentRow.querySelector('.quantite-col .badge.bg-success');
        if (badge) {
          badge.textContent = `${data.newDispo} dispo`;
          badge.classList.toggle('bg-danger', data.newDispo < 10);
          badge.classList.toggle('bg-success', data.newDispo >= 10);
        }
        const img = currentRow.querySelector('td:nth-child(2) img');
        if (img && data.newPhoto) {
          img.src = data.newPhoto + '?t=' + Date.now();  // force refresh
        }
        modifyModal.hide();
const modifyToast = new bootstrap.Toast(document.getElementById('modifyToast'));
modifyToast.show();

      } else {
        showErrorToast(data.message || 'Erreur lors de la modification.');
      }
    })
    .catch(err => {
      console.error(err);
      showErrorToast('Erreur réseau.');
    });
});
})


document.addEventListener('DOMContentLoaded', () => {
  const deleteModal = new bootstrap.Modal(document.getElementById('confirmDeleteModal'));
  const deleteItemName = document.getElementById('deleteItemName');
  const confirmDeleteButton = document.getElementById('confirmDeleteButton');
  let currentDeleteId = null;

  document.querySelectorAll('.delete-btn').forEach(button => {
    button.addEventListener('click', () => {
      currentDeleteId = button.getAttribute('data-stock-id');
      const itemName = button.getAttribute('data-stock-nom');
      deleteItemName.textContent = itemName;
      deleteModal.show();
    });
  });

  confirmDeleteButton.addEventListener('click', () => {
    if (currentDeleteId) {
      window.location.href = `supprimerStock.php?id=${currentDeleteId}`;
    }
  });
});

