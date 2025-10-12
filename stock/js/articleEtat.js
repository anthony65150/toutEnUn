// /stock/js/articleEtat.js
document.addEventListener('DOMContentLoaded', () => {
  if (!window.ARTICLE_ID) return;

  // === Helpers ===
  const $ = s => document.querySelector(s);

  function showError(msg) {
    const box = $('#etatError') || $('#etatAutreError') || $('#etatCompteurError');
    if (box) {
      box.textContent = msg;
      box.classList.remove('d-none');
    } else {
      alert(msg);
    }
  }

  async function fetchJSON(url, options = {}) {
    const res = await fetch(url, { credentials: 'same-origin', ...options });
    const raw = await res.text();
    let data;
    try {
      data = JSON.parse(raw);
    } catch {
      // Propage le HTML/notice PHP complet pour debug
      throw new Error('Réponse non-JSON du serveur:\n' + raw);
    }
    if (!res.ok || data?.ok === false) {
      // Fais remonter le message backend s'il existe, sinon le raw tronqué
      const msg = data?.msg || raw.slice(0, 200) || ('HTTP ' + res.status);
      throw new Error(msg);
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
        : (r.action === 'declarer_panne' ? '🚨 Panne déclarée'
           : (r.action === 'entretien_effectue' ? '🛠️ Entretien effectué' : '✅ Marqué OK'));
      const file = r.fichier ? ` – <a href="/${r.fichier}" target="_blank" rel="noopener">pièce jointe</a>` : '';
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

  function updateEtatBadge(state /* 'OK' | 'PANNE' */) {
    const badge = document.querySelector('[data-article-etat-badge]');
    if (!badge) return;
    if (state === 'PANNE') {
      badge.classList.remove('bg-success');
      badge.classList.add('bg-danger');
      badge.textContent = 'PANNE';
    } else {
      badge.classList.remove('bg-danger');
      badge.classList.add('bg-success');
      badge.textContent = 'OK';
    }
  }

  // Chargement initial de l'historique
  loadHist();

  // ===== Formulaire compteur =====
  const f1 = document.querySelector('#etatCompteurForm');
  if (f1){
    f1.addEventListener('submit', async (e)=>{
      e.preventDefault();
      const fd = new FormData(f1);
      fd.set('action', 'compteur_maj');
      if (!fd.has('article_id')) fd.set('article_id', String(window.ARTICLE_ID));
      try {
        await fetchJSON('/stock/ajax/ajax_article_etat_save.php', { method:'POST', body:fd });
        await loadHist();
      } catch (err) { showError(err.message); }
    });

    f1.querySelectorAll('button[data-action]').forEach(btn=>{
      btn.addEventListener('click', async (e)=>{
        e.preventDefault();
        const fd = new FormData(f1);
        fd.set('action', btn.dataset.action);
        if (!fd.has('article_id')) fd.set('article_id', String(window.ARTICLE_ID));
        try {
          await fetchJSON('/stock/ajax/ajax_article_etat_save.php', { method:'POST', body:fd });
          if (btn.dataset.action === 'declarer_ok') updateEtatBadge('OK');
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
        const action = (fd.get('action') || '').toString();
        await fetchJSON('/stock/ajax/ajax_article_etat_save.php', { method:'POST', body:fd });
        if (action === 'declarer_panne') updateEtatBadge('PANNE');
        if (action === 'declarer_ok')    updateEtatBadge('OK');
        f2.reset();
        await loadHist();
      } catch (err) { showError(err.message); }
    });
  }

  // ======= Déclarer un problème pour profil "compteur d'heures" via MODALE =======
  const btnDeclare   = document.querySelector('#btnDeclarePanne');
  const modalEl      = document.getElementById('modalDeclarePanne');
  const confirmBtn   = document.querySelector('#confirmDeclarePanne');
  const hoursInput   = document.getElementById('panneHours');        // <input number optionnel>
  const chantierIdEl = document.getElementById('panneChantierId');   // <input hidden> si présent

  // Ouvrir la modale
  if (btnDeclare && modalEl) {
    btnDeclare.addEventListener('click', (e) => {
      e.preventDefault();
      const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
      modal.show();
    });
  }

  // Envoyer la panne depuis la modale (anti double clic + envoi compteur & pièce jointe)
  if (confirmBtn && modalEl && !confirmBtn.dataset.bound) {
    confirmBtn.dataset.bound = '1';
    confirmBtn.setAttribute('type','button'); // sécurité si pas déjà fait dans le HTML

    confirmBtn.addEventListener('click', async (e) => {
      e.preventDefault();
      if (confirmBtn.dataset.loading === '1') return; // 🔒 anti-double
      confirmBtn.dataset.loading = '1';
      confirmBtn.disabled = true;

      const textarea = document.getElementById('panneComment');
      const fileInput = document.getElementById('panneFile');
      const comment = (textarea?.value || '').trim();

      if (!comment) {
        showError('Merci de saisir une description du problème.');
        confirmBtn.disabled = false;
        confirmBtn.dataset.loading = '0';
        return;
      }

      const fd = new FormData();
      fd.append('action', 'declarer_panne');
      fd.append('article_id', String(window.ARTICLE_ID));
      fd.append('commentaire', comment);

      // compteur optionnel
      if (hoursInput && hoursInput.value !== '') {
        const v = parseInt(hoursInput.value, 10);
        if (!Number.isNaN(v) && v >= 0) fd.append('hours', String(v));
      }
      // chantier_id optionnel
      if (chantierIdEl && chantierIdEl.value) {
        fd.append('chantier_id', chantierIdEl.value);
      }
      // fichier optionnel
      if (fileInput?.files?.[0]) fd.append('fichier', fileInput.files[0]);

      try {
        await fetchJSON('/stock/ajax/ajax_article_etat_save.php', { method:'POST', body:fd });

        // Fermer proprement la modale
        const modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
        modalInstance.hide();

        // Reset champs
        if (textarea) textarea.value = '';
        if (fileInput) fileInput.value = '';
        if (hoursInput) hoursInput.value = '';

        // Feedback visuel
        updateEtatBadge('PANNE');

        // Recharger l’historique pour voir la ligne "panne déclarée"
        await loadHist();

      } catch (err) {
        showError(err.message);
      } finally {
        confirmBtn.disabled = false;
        confirmBtn.dataset.loading = '0';
      }
    }, { passive: true });
  }
});
