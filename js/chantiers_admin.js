document.addEventListener('DOMContentLoaded', function () {
    const chantierForm = document.getElementById('chantierForm');
    const chantierModalLabel = document.getElementById('chantierModalLabel');
    const chantierIdInput = document.getElementById('chantierId');
    const chantierNomInput = document.getElementById('chantierNom');
    const chantierDescInput = document.getElementById('chantierDesc');
    const chantierDebutInput = document.getElementById('chantierDebut');
    const chantierFinInput = document.getElementById('chantierFin');
    const chefChantierSelect = document.getElementById('chefChantier');
    const deleteForm = document.getElementById('deleteForm');
    const deleteIdInput = document.getElementById('deleteId');

    // 🟡 Bouton Créer chantier → réinitialise le form
    document.querySelector('[data-bs-target="#chantierModal"]').addEventListener('click', function () {
        chantierForm.reset();
        chantierIdInput.value = '';
        chantierModalLabel.textContent = 'Créer un chantier';
    });

    // 🟠 Bouton Modifier → remplit le form avec les données
    document.querySelectorAll('.edit-btn').forEach(button => {
        button.addEventListener('click', function () {
            const chantierId = this.dataset.id;
            const chantierNom = this.dataset.nom;
            const chantierDesc = this.dataset.description;
            const chantierDebut = this.dataset.debut;
            const chantierFin = this.dataset.fin;
            const chefId = this.dataset.chef;

            chantierIdInput.value = chantierId;
            chantierNomInput.value = chantierNom;
            chantierDescInput.value = chantierDesc;
            chantierDebutInput.value = chantierDebut;
            chantierFinInput.value = chantierFin;
            chefChantierSelect.value = chefId;

            chantierModalLabel.textContent = 'Modifier le chantier';
        });
    });

    // 🔴 Bouton Supprimer → prépare le form avec l’ID
    document.querySelectorAll('.delete-btn').forEach(button => {
        button.addEventListener('click', function () {
            const chantierId = this.dataset.id;
            deleteIdInput.value = chantierId;
        });
    });
});
