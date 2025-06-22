document.addEventListener('DOMContentLoaded', () => {
  const toggler = document.querySelector('.navbar-toggler');
  const menu = document.querySelector('#navbarBurgerMenu');

  toggler.addEventListener('click', () => {
    // On crée une instance Bootstrap Collapse si elle n'existe pas
    let bsCollapse = bootstrap.Collapse.getInstance(menu);
    if (!bsCollapse) {
      bsCollapse = new bootstrap.Collapse(menu, { toggle: false });
    }

    // On bascule l'état (ouvrir/fermer)
    bsCollapse.toggle();
  });
});
