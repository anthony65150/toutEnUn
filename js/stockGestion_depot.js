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

    function sendTransfer(payload) {
        fetch("transferStock_depot.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload)
        })
        .then(res => res.text())
        .then(text => {
            try {
                const data = JSON.parse(text);

                if (!data.success) {
                    document.getElementById("errorToastMessage").textContent = data.message;
                    showToast("errorToast");
                    return;
                }

                const row = document.querySelector(`button[data-stock-id="${payload.stockId}"]`)?.closest("tr");
                if (row) {
                    const dispoCell = row.querySelector("td:nth-child(3) .badge");
                    if (dispoCell) {
                        dispoCell.textContent = data.disponible;
                        dispoCell.classList.remove("bg-danger", "bg-success");
                        dispoCell.classList.add(data.disponible < 10 ? "bg-danger" : "bg-success");
                    }

                    const chantierCell = row.querySelector("td:nth-child(4)");
                    if (chantierCell && data.chantiersHtml) {
                        chantierCell.innerHTML = data.chantiersHtml;
                    }
                }

                bootstrap.Modal.getInstance(transferModal)?.hide();
                showToast("transferToast");

            } catch (e) {
                document.getElementById("errorToastMessage").textContent = "Erreur serveur : " + e.message;
                showToast("errorToast");
                console.error(e, text);
            }
        })
        .catch(error => {
            document.getElementById("errorToastMessage").textContent = "Erreur réseau.";
            showToast("errorToast");
            console.error(error);
        });
    }

    confirmButton.addEventListener("click", () => {
        const qty = parseInt(transferQtyInput.value);
        const destination = destinationSelect.value;
        const stockId = modalStockIdInput.value;

        if (!qty || !destination || qty < 1) {
            document.getElementById("errorToastMessage").textContent = "Veuillez remplir tous les champs.";
            showToast("errorToast");
            return;
        }

        sendTransfer({ stockId, destination, qty });
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
