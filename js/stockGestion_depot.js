document.addEventListener("DOMContentLoaded", () => {
    const transferModal = document.getElementById("transferModal");
    const confirmButton = document.getElementById("confirmTransfer");
    const transferQtyInput = document.getElementById("transferQty");
    const destinationSelect = document.getElementById("destination");
    const modalStockIdInput = document.getElementById("modalStockId");

    function showToast(id) {
        const toast = new bootstrap.Toast(document.getElementById(id));
        toast.show();
    }

    function showErrorToast(message) {
        document.getElementById("errorToastMessage").textContent = message;
        showToast("errorToast");
    }

    function sendTransfer(payload) {
        fetch("transferStock_depot.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                showErrorToast(data.message || "Erreur lors du transfert.");
                return;
            }

            // Mise à jour de la quantité disponible dans le tableau
            const stockId = modalStockIdInput.value;
            const rows = document.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const btn = row.querySelector('.transfer-btn');
                if (btn && btn.getAttribute('data-stock-id') === stockId) {
                    const dispoSpan = row.querySelector('.quantite-disponible');
                    if (dispoSpan) {
                        dispoSpan.textContent = data.quantiteDispo;
                        dispoSpan.className = 'badge quantite-disponible ' + (data.quantiteDispo < 10 ? 'bg-danger' : 'bg-success');
                    }
                }
            });

            bootstrap.Modal.getInstance(transferModal)?.hide();
            showToast("transferToast");
        })
        .catch(error => {
            console.error("Erreur réseau :", error);
            showErrorToast("Erreur réseau ou serveur.");
        });
    }

    confirmButton.addEventListener("click", () => {
        const qty = parseInt(transferQtyInput.value, 10);
        const destinationId = destinationSelect.value;
        const stockId = modalStockIdInput.value;

        if (!qty || !destinationId || qty < 1) {
            showErrorToast("Veuillez remplir tous les champs.");
            return;
        }

        sendTransfer({ stockId, destination: destinationId, qty });
        // Ou mieux : sendTransfer({ stockId, destinationId, qty });
    });

    document.querySelectorAll(".transfer-btn").forEach(button => {
        button.addEventListener("click", () => {
            modalStockIdInput.value = button.getAttribute("data-stock-id");
            destinationSelect.value = '';
            transferQtyInput.value = '';
            new bootstrap.Modal(transferModal).show();
        });
    });
});
