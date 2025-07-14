document.addEventListener('DOMContentLoaded', () => {
  const transferModal = document.getElementById('transferModal');
  const confirmButton = document.getElementById('confirmTransfer');
  const destinationSelect = document.getElementById('destination');
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
    fetch('transferStock_chef.php', {
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

        // Pas de mise à jour directe des colonnes → juste le toast
        bootstrap.Modal.getInstance(transferModal)?.hide();
        showToast("transferToast");
      })
      .catch(error => {
        console.error("❌ Erreur réseau :", error);
        showErrorToast("Erreur réseau ou serveur.");
      });
  }

  confirmButton.addEventListener('click', () => {
    const destinationValue = destinationSelect.value;
    const qty = parseInt(qtyInput.value, 10);
    const stockId = modalStockIdInput.value;

    if (!destinationValue || isNaN(qty) || qty < 1) {
      showErrorToast("Veuillez remplir tous les champs.");
      return;
    }

    const [destinationType, destinationId] = destinationValue.split('_');

    const payload = { stockId, destinationType, destinationId: parseInt(destinationId, 10), qty };
    sendTransfer(payload);
  });

  document.querySelectorAll('.transfer-btn').forEach(button => {
    button.addEventListener('click', () => {
      modalStockIdInput.value = button.getAttribute('data-stock-id');
      qtyInput.value = '';
      destinationSelect.value = '';
      new bootstrap.Modal(transferModal).show();
    });
  });
});
