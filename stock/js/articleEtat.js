// stock/js/articleEtat.js
document.addEventListener('DOMContentLoaded', () => {
  if (!window.ARTICLE_ID) return;

  // === Helpers ===
  const $ = s => document.querySelector(s);

  function showError(msg) {
    const box = $('#etatError') || $('#etatAutreError') || $('#etatCompteurError');
    if (box) {
      box.innerHTML = msg;
      box.classList.remove('d-none');
    } else {
      alert(msg);
    }
  }

  async function fetchJSON(url, options = {}) {
    const res = await fetch(url, { credentials: 'same-origin', ...options });
    const raw = await res.text();             // <- d'abord texte
    let data;
    try { data = JSON.parse(raw); }           // <- puis JSON
    catch (e) {
      // On remonte l’HTML complet pour déboguer (notices PHP, etc.)
      throw new Error('Réponse non-JSON du serveur:\n' + raw);
    }
    if (!res.ok || data.ok === false) {
      throw new Error(data.msg || 'Erreur serveur');
    }
    return data;
  }

  function renderHist(el, rows){
    const c = document.querySelector(el);
    if (!c) return;
    if (!rows || !rows.length) { c.innerHTML = '<em>Aucun enregistrement</em>'; return; }
    c.innerHTML = rows.map(r => {
      const label = r.action === 'compteur_maj'
        ? `Compteur mis à jour: <strong>${(r.valeur_int ?? '')} h</strong>`
        : (r.action === 'declarer_panne' ? '🚨 Panne déclarée' : '✅ Marqué OK');
      const file = r.fichier ? ` – <a href="/${r.fichier}" target="_blank">pièce jointe</a>` : '';
      const who  = ((r.prenom||'') + ' ' + (r.nom||'')).trim();
      const com  = r.commentaire ? ' — ' + r.commentaire : '';
      const dt   = new Date(r.created_at).toLocaleString();
      return `<div>• ${dt} – ${label}${file} <span class="text-muted">${who ? '('+who+')' : ''}</span>${com}</div>`;
    }).join('');
  }

  async function loadHist(){
    try {
      const j = await fetchJSON('/stock/ajax/ajax_article_etat_list.php?article_id=' + encodeURIComponent(window.ARTICLE_ID));
      renderHist('#etatCompteurHistorique', j.rows);
      renderHist('#etatAutreHistorique', j.rows);
    } catch (err) {
      showError(err.message);
    }
  }
  loadHist();

  // ===== Formulaire compteur =====
  const f1 = document.querySelector('#etatCompteurForm');
  if (f1){
    // Submit "classique" -> compteur_maj
    f1.addEventListener('submit', async (e)=>{
      e.preventDefault();
      const fd = new FormData(f1);
      fd.set('action', 'compteur_maj');
      // S'assure qu'on envoie bien l'article_id
      if (!fd.has('article_id')) fd.set('article_id', String(window.ARTICLE_ID));
      try {
        await fetchJSON('/stock/ajax/ajax_article_etat_save.php', { method:'POST', body:fd });
        await loadHist();
      } catch (err) { showError(err.message); }
    });

    // Boutons d’action (OK / PANNE / etc.)
    f1.querySelectorAll('button[data-action]').forEach(btn=>{
      // IMPORTANT: prévenir le double submit si le bouton est type=submit
      btn.addEventListener('click', async (e)=>{
        e.preventDefault();
        const fd = new FormData(f1);
        fd.set('action', btn.dataset.action);
        if (!fd.has('article_id')) fd.set('article_id', String(window.ARTICLE_ID));
        try {
          await fetchJSON('/stock/ajax/ajax_article_etat_save.php', { method:'POST', body:fd });
          await loadHist();
        } catch (err) { showError(err.message); }
      });
    });
  }

  // ===== Formulaire "Autres" (déclarer un problème + photo) =====
  const f2 = document.querySelector('#etatAutreForm');
  if (f2){
    f2.addEventListener('submit', async (e)=>{
      e.preventDefault();
      const fd = new FormData(f2); // inclut description, fichier (optionnel), etc.
      if (!fd.has('article_id')) fd.set('article_id', String(window.ARTICLE_ID));
      try {
        await fetchJSON('/stock/ajax/ajax_article_etat_save.php', { method:'POST', body:fd });
        f2.reset();
        await loadHist();
      } catch (err) { showError(err.message); }
    });
  }
});
