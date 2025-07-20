// darkMode.js

document.addEventListener('DOMContentLoaded', function() {
    const button = document.getElementById('toggleDarkMode');

    // Fonction pour changer l’icône du bouton
    function updateIcon() {
        button.textContent = document.body.classList.contains('dark-mode') ? '☀️' : '🌙';
    }

    // Charger le mode au démarrage
    if (localStorage.getItem('mode') === 'dark') {
        document.body.classList.add('dark-mode');
    }
    updateIcon();

    // Basculer le mode au clic
    button.addEventListener('click', function() {
        document.body.classList.toggle('dark-mode');
        const mode = document.body.classList.contains('dark-mode') ? 'dark' : 'light';
        localStorage.setItem('mode', mode);
        updateIcon();
    });
});
