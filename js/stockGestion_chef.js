document.addEventListener('DOMContentLoaded', () => {
  const transferModal = document.getElementById('transferModal');
  const confirmButton = document.getElementById('confirmTransfer');
  const destinationSelect = document.getElementById('destination');
  const transferQtyInput = document.getElementById('transferQty');
  const modalStockIdInput = document.getElementById('modalStockId');

  function sendTransfer(payload) {
    fetch('transferStock_chef.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
      if (!data.success) {
        alert(data.message || "Erreur lors du transfert.");
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

        const chantierCell = row.querySelector(".chantier-col");
        if (chantierCell) {
          chantierCell.textContent = data.chantierQuantite ?? 0;
        }
      }

      bootstrap.Modal.getInstance(transferModal)?.hide();
    })
    .catch(error => {
      console.error("Erreur réseau :", error);
      alert("Erreur réseau.");
    });
  }

  confirmButton.addEventListener('click', () => {
    const destination = destinationSelect.value;
    const qty = parseInt(transferQtyInput.value, 10);
    const stockId = modalStockIdInput.value;

    if (!destination || isNaN(qty) || qty < 1) {
      alert("Veuillez remplir tous les champs.");
      return;
    }

    sendTransfer({ stockId, destination, qty });
  });

  document.querySelectorAll('.transfer-btn').forEach(button => {
    button.addEventListener('click', () => {
      modalStockIdInput.value = button.getAttribute('data-stock-id');
      destinationSelect.value = '';
      transferQtyInput.value = '';
      new bootstrap.Modal(transferModal).show();
    });
  });
});

