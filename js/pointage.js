document.addEventListener('DOMContentLoaded', () => {
  const table = document.querySelector('#pointageApp table');
  const searchInput = document.getElementById('searchInput');

  // ----- Filtre chantier (montre lignes où l'employé a ce chantier dans data-chantiers)
  document.getElementById('chantierFilters')?.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-chantier]');
    if (!btn) return;
    [...btn.parentElement.querySelectorAll('button')].forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    const wanted = btn.dataset.chantier; // 'all' ou id
    document.querySelectorAll('#pointageApp tbody tr').forEach(tr => {
      const list = (tr.dataset.chantiers || '');
      if (wanted === 'all' || list.split(',').includes(String(wanted))) {
        tr.style.display = '';
      } else {
        tr.style.display = 'none';
      }
    });
  });

  // ----- Recherche employé
  searchInput?.addEventListener('input', () => {
    const q = (searchInput.value || '').toLowerCase();
    document.querySelectorAll('#pointageApp tbody tr').forEach(tr => {
      tr.style.display = tr.dataset.name.includes(q) ? '' : 'none';
    });
  });

  // ----- Effacer cellule (heures + A/R)
  table?.addEventListener('click', async (e) => {
    const clearBtn = e.target.closest('.clear-cell');
    if (!clearBtn) return;
    const td = clearBtn.closest('td');
    const tr = clearBtn.closest('tr');

    // remet l'affichage par défaut (local)
    td.querySelectorAll('.present-btn').forEach(b => {
      b.classList.remove('btn-success'); b.classList.add('btn-outline-success');
      if (b.dataset.hours === '7') b.textContent = 'Présent 7h';
    });
    td.querySelectorAll('.conduite-btn').forEach(b => {
      b.disabled = false;
      b.classList.toggle('btn-primary', b.dataset.type === 'A' && false);
      b.classList.toggle('btn-success', b.dataset.type === 'R' && false);
      b.classList.toggle('btn-outline-primary', b.dataset.type === 'A');
      b.classList.toggle('btn-outline-success', b.dataset.type === 'R');
    });
    td.querySelector('.chantier-select')?.selectedIndex = 0;
    // TODO: côté serveur, prévoir un endpoint pour "clear" si tu veux aussi purger la base
  });

  // Clic Présent Xh (supporte 8.25)
table?.addEventListener('click', async (e) => {
  const btn = e.target.closest('.present-btn');
  if (!btn) return;

  const td = btn.closest('td');
  const tr = btn.closest('tr');
  const userId = tr.dataset.userId;
  const dateIso = td.dataset.date;
  const hours = btn.dataset.hours;
  const chantierId = td.querySelector('.chantier-select')?.value || '';

  try {
    const res = await fetch('pointage_present.php', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({ utilisateur_id:userId, date:dateIso, hours, chantier_id:chantierId })
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.message || 'Erreur serveur');

    // reset visuel
    td.querySelectorAll('.present-btn').forEach(b => {
      b.classList.remove('btn-success'); b.classList.add('btn-outline-success');
      if (b.dataset.hours === '8.25') b.textContent = 'Présent 8h15';
    });
    btn.classList.remove('btn-outline-success'); btn.classList.add('btn-success');
    if (btn.dataset.hours === '8.25') btn.textContent = '8,25 h';

    // réactive conduite + vide badge absence si existait
    td.querySelectorAll('.conduite-btn').forEach(b => b.disabled = false);
    const absBtn = td.querySelector('.absence-btn');
    if (absBtn) { absBtn.classList.remove('btn-danger'); absBtn.classList.add('btn-outline-danger'); absBtn.textContent = 'Abs.'; }
  } catch (err) {
    alert(err.message);
  }
});

// Clic Absence (menu)
table?.addEventListener('click', async (e) => {
  const a = e.target.closest('.absence-choice');
  if (!a) return;

  const reason = a.dataset.reason;
  const td = a.closest('td');
  const tr = a.closest('tr');
  const userId = tr.dataset.userId;
  const dateIso = td.dataset.date;

  try {
    const res = await fetch('pointage_absence.php', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({ utilisateur_id:userId, date:dateIso, reason })
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.message || 'Erreur serveur');

    // MAJ visuelle : badge + désactivation Présent/Conduite
    const absBtn = td.querySelector('.absence-btn');
    if (absBtn) {
      absBtn.classList.remove('btn-outline-danger'); absBtn.classList.add('btn-danger');
      absBtn.textContent = 'Abs. ' + (reason === 'conges' ? 'Congés' : (reason === 'maladie' ? 'Maladie' : 'Injustifié'));
    }
    td.querySelectorAll('.present-btn').forEach(b => {
      b.classList.remove('btn-success'); b.classList.add('btn-outline-success'); b.disabled = true;
      if (b.dataset.hours === '8.25') b.textContent = 'Présent 8h15';
    });
    td.querySelectorAll('.conduite-btn').forEach(b => { b.disabled = true; });
  } catch (err) {
    alert(err.message);
  }
});


  // ----- Clic Conduite A/R
  table?.addEventListener('click', async (e) => {
    const btn = e.target.closest('.conduite-btn');
    if (!btn || btn.disabled) return;

    const td = btn.closest('td');
    const tr = btn.closest('tr');
    const userId = tr.dataset.userId;
    const dateIso = td.dataset.date;
    const type = btn.dataset.type;
    const chantierId = td.querySelector('.chantier-select')?.value;

    if (!chantierId) { alert('Choisis un chantier dans la cellule.'); return; }

    try {
      const res = await fetch('pointage_conduite.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
          chantier_id: chantierId,
          type,
          utilisateur_id: userId,
          date_pointage: dateIso
        })
      });
      const data = await res.json();
      if (data.success) {
        if (type === 'A') {
          btn.classList.remove('btn-outline-primary'); btn.classList.add('btn-primary');
        } else {
          btn.classList.remove('btn-outline-success'); btn.classList.add('btn-success');
        }
        btn.disabled = true;
      } else {
        alert('Erreur conduite : ' + (data.message || ''));
      }
    } catch (err) {
      alert('Erreur réseau');
    }
  });
});
