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
    document.querySelector('[data-bs-target="#chantierModal"]').addEventListener('click', function () {
        document.getElementById('chantierForm').reset();
        document.getElementById('chantierId').value = '';
        document.getElementById('chantierModalLabel').textContent = 'Créer un chantier';
    });

    // Préparer ID pour suppression
    document.querySelectorAll('.delete-btn').forEach(button => {
        button.addEventListener('click', function () {
            const chantierId = this.dataset.id;
            document.getElementById('deleteId').value = chantierId;
        });
    });
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

    // Supprimer manuellement le backdrop s'il reste
    const backdrop = document.querySelector('.modal-backdrop');
    if (backdrop) backdrop.remove();

    // Supprimer la classe bootstrap qui bloque le scroll
    document.body.classList.remove('modal-open');
    document.body.style = '';
}


// Fonction pour afficher le toast avec message personnalisé
function showChantierToast(message = 'Chantier enregistré avec succès') {
    const toastEl = document.getElementById('chantierToast');
    const toastMsg = document.getElementById('chantierToastMsg');
    toastMsg.textContent = message;
    const toast = new bootstrap.Toast(toastEl);
    toast.show();
}
