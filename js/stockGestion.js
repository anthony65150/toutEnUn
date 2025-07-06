document.addEventListener('DOMContentLoaded', () => {
  const transferModal = document.getElementById('transferModal');
  const confirmButton = document.getElementById('confirmTransfer');
  const destinationSelect = document.getElementById('destination');
  const transferQtyInput = document.getElementById('transferQty');
  const modalStockIdInput = document.getElementById('modalStockId');
  const sourceChantierInput = document.getElementById('sourceChantier'); // null pour les non-admins
  let currentStockId = null;

  function sendTransfer(payload) {
  fetch('transferStock.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(payload)
  })
  .then(async response => {
    const text = await response.text(); // on lit le contenu brut
    try {
      const data = JSON.parse(text);
      if (!response.ok) {
        throw new Error(data.error || 'Erreur HTTP ' + response.status);
      }

      if (data.error) {
        alert("Erreur : " + data.error);
        return;
      }

      // ✅ Affiche le toast de succès
      const toast = new bootstrap.Toast(document.getElementById('transferToast'));
      toast.show();

      // ✅ Mise à jour du tableau
      const row = document.querySelector(`button[data-stock-id="${payload.stock_id}"]`)?.closest('tr');
      if (row) {
  const qtyCell = row.querySelector('.quantite-col');
if (qtyCell) {
  qtyCell.innerHTML = `
    <span class="badge bg-success">${data.disponible} dispo</span>
    <span class="badge bg-warning text-dark">${data.surChantier} sur chantier</span>
  `;
}


        const chantierCell = row.querySelector('.chantier-col');
        if (chantierCell) {
          chantierCell.textContent = data.chantierQuantite ?? 0;
        }

        const adminCell = row.querySelector('.admin-col');
        if (adminCell && data.chantiersHtml) {
          adminCell.innerHTML = data.chantiersHtml;
        }
      }

      bootstrap.Modal.getInstance(transferModal)?.hide();
    } catch (e) {
      console.error("❌ Erreur JSON ou HTTP : ", e, text);
      alert("Erreur de réponse serveur : " + e.message);
    }
  })
  .catch(error => {
    console.error("❌ Erreur réseau :", error);
    alert("Erreur réseau ou serveur.");
  });
}


  confirmButton.addEventListener('click', () => {
    const destination = destinationSelect.value;
    const qty = parseInt(transferQtyInput.value, 10);
    const stockId = modalStockIdInput.value;
    const sourceChantier = sourceChantierInput ? sourceChantierInput.value : null;

    if (!destination || isNaN(qty) || qty < 1) {
      alert("Veuillez remplir tous les champs.");
      return;
    }

    const payload = {
      stock_id: stockId,
      destination: destination,
      quantite: qty
    };

    if (sourceChantier) {
      payload.source = sourceChantier;
    }

    sendTransfer(payload);
  });

  document.querySelectorAll('.transfer-btn').forEach(button => {
    button.addEventListener('click', () => {
      currentStockId = button.getAttribute('data-stock-id');
      modalStockIdInput.value = currentStockId;
      destinationSelect.value = '';
      transferQtyInput.value = '';
      if (sourceChantierInput) sourceChantierInput.value = '';
      new bootstrap.Modal(transferModal).show();
    });
  });
});
