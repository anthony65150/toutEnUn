// stock/js/articleEtat.js
document.addEventListener('DOMContentLoaded', () => {
  if (!window.ARTICLE_ID) return;

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

  function loadHist(){
    fetch('/stock/ajax/ajax_article_etat_list.php?article_id=' + window.ARTICLE_ID)
      .then(r=>r.json()).then(j=>{
        if (!j.ok) return;
        renderHist('#etatCompteurHistorique', j.rows);
        renderHist('#etatAutreHistorique', j.rows);
      });
  }
  loadHist();

  // Compteur
  const f1 = document.querySelector('#etatCompteurForm');
  if (f1){
    f1.addEventListener('submit', (e)=>{
      e.preventDefault();
      const fd = new FormData(f1);
      fd.set('action', 'compteur_maj');
      fetch('/stock/ajax/ajax_article_etat_save.php', { method:'POST', body:fd })
        .then(r=>r.json()).then(j=>{ if(j.ok){ loadHist(); }});
    });
    f1.querySelectorAll('button[data-action]').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const fd = new FormData(f1);
        fd.set('action', btn.dataset.action);
        fetch('/stock/ajax/ajax_article_etat_save.php', { method:'POST', body:fd })
          .then(r=>r.json()).then(j=>{ if(j.ok){ loadHist(); }});
      });
    });
  }

  // Autres
  const f2 = document.querySelector('#etatAutreForm');
  if (f2){
    f2.addEventListener('submit', (e)=>{
      e.preventDefault();
      const fd = new FormData(f2);
      fetch('/stock/ajax/ajax_article_etat_save.php', { method:'POST', body:fd })
        .then(r=>r.json()).then(j=>{ if(j.ok){ f2.reset(); loadHist(); }});
    });
  }
});
