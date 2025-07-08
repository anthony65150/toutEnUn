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

