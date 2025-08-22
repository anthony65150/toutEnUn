document.addEventListener('DOMContentLoaded', function () {
    // Remplissage de la modal "Modifier"
    document.querySelectorAll('.edit-btn').forEach(button => {
        button.addEventListener('click', function () {
            const id = this.dataset.id;
            const nom = this.dataset.nom;
            const description = this.dataset.description;
            const debut = this.dataset.debut;
            const fin = this.dataset.fin;
            const chef = this.dataset.chef;

            // Remplir les champs de la modal
            document.getElementById('chantierIdEdit').value = id;
            document.getElementById('chantierNomEdit').value = nom;
            document.getElementById('chantierDescEdit').value = description;
            document.getElementById('chantierDebutEdit').value = debut;
            document.getElementById('chantierFinEdit').value = fin;

            // Sélectionner le chef dans le <select>
            const chefSelect = document.getElementById('chefChantierEdit');
            if (chefSelect) {
                [...chefSelect.options].forEach(option => {
                    option.selected = option.value === chef;
                });
            }

            // Fermer toute modal encore visible AVANT ouverture
            closeAllModals();

            // Ouvrir la modal de modification
            const modal = new bootstrap.Modal(document.getElementById('chantierEditModal'));
            modal.show();
        });
    });

    // Reset form Création
    const createBtn = document.querySelector('[data-bs-target="#chantierModal"]');
    if (createBtn) {
        createBtn.addEventListener('click', function () {
            document.getElementById('chantierForm').reset();
            document.getElementById('chantierId').value = '';
            document.getElementById('chantierModalLabel').textContent = 'Créer un chantier';
        });
    }

    // Préparer ID pour suppression
    document.querySelectorAll('.delete-btn').forEach(button => {
        button.addEventListener('click', function () {
            const chantierId = this.dataset.id;
            document.getElementById('deleteId').value = chantierId;
        });
    });

    // --- RECHERCHE CHANTIERS ---
    const input = document.getElementById('chantierSearchInput');
    const tbody = document.getElementById('chantiersTableBody');

    if (input && tbody) {
        // crée la ligne "aucun résultat" si absente
        let noRow = document.getElementById('noResultsChantier');
        if (!noRow) {
            noRow = document.createElement('tr');
            noRow.id = 'noResultsChantier';
            noRow.className = 'd-none';
            noRow.innerHTML = `<td colspan="5" class="text-muted text-center py-4">Aucun chantier trouvé</td>`;
            tbody.appendChild(noRow);
        }

        const rows = () => Array.from(tbody.querySelectorAll('tr')).filter(tr => tr !== noRow);
        const normalize = s => (s || '')
            .toString()
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '') // supprime accents
            .trim();

        const filter = () => {
            const q = normalize(input.value);
            let visible = 0;
            rows().forEach(tr => {
                const show = !q || normalize(tr.textContent).includes(q);
                tr.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            noRow.classList.toggle('d-none', visible !== 0);
        };

        let t;
        input.addEventListener('input', () => {
            clearTimeout(t);
            t = setTimeout(filter, 120); // petit debounce
        });

        filter(); // init
    }
});

// Fermer proprement toutes les modals + backdrop
function closeAllModals() {
    document.querySelectorAll('.modal.show').forEach(m => {
        const instance = bootstrap.Modal.getInstance(m);
        if (instance) instance.hide();
        setTimeout(() => {
            const backdrop = document.querySelector('.modal-backdrop');
            if (backdrop) backdrop.remove();
            document.body.classList.remove('modal-open');
            document.body.style = '';
        }, 300); // délai pour que le hide() soit terminé
    });

    // Sécurité
    const backdrop = document.querySelector('.modal-backdrop');
    if (backdrop) backdrop.remove();
    document.body.classList.remove('modal-open');
    document.body.style = '';
}

// Toast
function showChantierToast(message = 'Chantier enregistré avec succès') {
    const toastEl = document.getElementById('chantierToast');
    const toastMsg = document.getElementById('chantierToastMsg');
    toastMsg.textContent = message;
    const toast = new bootstrap.Toast(toastEl);
    toast.show();
}
