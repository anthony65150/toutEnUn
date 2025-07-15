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
      } else {
        showErrorToast(data.message);
      }
    })
    .catch(() => showErrorToast('Erreur réseau ou serveur.'));
  });

  // ----- MODIFIER -----
  document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      currentRow = btn.closest('tr');
      document.getElementById('modifyStockId').value = btn.dataset.stockId;
      document.getElementById('modifyNom').value = btn.dataset.stockNom;
      document.getElementById('modifyQty').value = '';
      document.getElementById('modifyPhoto').value = '';
      modifyModal.show();
    });
  });

  document.getElementById('confirmModify').addEventListener('click', () => {
    const formData = new FormData();
    formData.append('stockId', document.getElementById('modifyStockId').value);
    formData.append('nom', document.getElementById('modifyNom').value);
    formData.append('quantite', document.getElementById('modifyQty').value);
    const photoFile = document.getElementById('modifyPhoto').files[0];
    if (photoFile) formData.append('photo', photoFile);

    fetch('modifierStock.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        if (currentRow) {
          // Mettre à jour le nom (quantité totale NE CHANGE PAS sauf si admin le modifie)
          currentRow.querySelector('td:first-child').textContent = `${data.newNom} (${data.newQuantiteTotale})`;

          // Mettre à jour badge dépôt 1
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

          // Mettre à jour photo si changée
          const img = currentRow.querySelector('td:nth-child(2) img');
          if (img && data.newPhotoUrl) {
            img.src = data.newPhotoUrl + '?t=' + Date.now();
          }
        }
        modifyModal.hide();
        showToast('modifyToast');
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
