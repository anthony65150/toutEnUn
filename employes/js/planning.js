// Grille hebdo : pastilles de chantiers -> cellules (employé x jour)

function toast(msg){
  let t = document.getElementById('plan-toast');
  if(!t){
    t=document.createElement('div');
    t.id='plan-toast';
    Object.assign(t.style,{
      position:'fixed',right:'16px',bottom:'16px',background:'#0b1220',color:'#e5e7eb',
      border:'1px solid rgba(255,255,255,.12)',padding:'10px 14px',borderRadius:'10px',
      boxShadow:'0 10px 25px rgba(0,0,0,.35)',opacity:'0',transform:'translateY(8px)',transition:'.2s',zIndex:9999
    });
    document.body.appendChild(t);
  }
  t.textContent=msg;
  requestAnimationFrame(()=>{ t.style.opacity='1'; t.style.transform='translateY(0)';
    clearTimeout(toast._t); toast._t=setTimeout(()=>{ t.style.opacity='0'; t.style.transform='translateY(8px)'; },1400);
  });
}

function attachDragSources(){
  // palette
  document.querySelectorAll('#palette .chip').forEach(chip=>{
    chip.addEventListener('dragstart', e=>{
      e.dataTransfer.setData('chantier_id', chip.dataset.chantierId);
      e.dataTransfer.setData('color', chip.dataset.chipColor || '');
      e.dataTransfer.setData('label', chip.innerText.trim());
      e.dataTransfer.effectAllowed = 'copy';
    });
  });

  // permettre de re-drag une assignation depuis une cellule
  document.querySelectorAll('.assign-chip').forEach(ch=>{
    ch.addEventListener('dragstart', e=>{
      const p = ch.closest('.cell-drop');
      e.dataTransfer.setData('chantier_id', ch.dataset.chantierId);
      e.dataTransfer.setData('color', ch.style.background || '');
      e.dataTransfer.setData('label', (ch.childNodes[0]?.nodeValue || '').trim());
      e.dataTransfer.effectAllowed = 'move';
    });
    ch.setAttribute('draggable','true');
  });
}

function makeAssign(label, color, chantierId){
  const span = document.createElement('span');
  span.className='assign-chip';
  span.style.background = color || '#334155';
  span.dataset.chantierId = chantierId;
  span.innerHTML = `${label} <span class="x" title="Retirer">×</span>`;

  // retirer
  span.querySelector('.x')?.addEventListener('click', ()=>{
    const cell = span.closest('.cell-drop');
    if(!cell) return;
    const empId  = cell.dataset.emp;
    const date   = cell.dataset.date;

    fetch(window.API_DELETE,{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      credentials:'same-origin',
      body: new URLSearchParams({ emp_id: empId, date })
    })
    .then(async r=>{
      const raw=await r.text(); let d;
      try{ d=JSON.parse(raw);}catch{ throw new Error(`HTTP ${r.status} – ${raw.slice(0,200)}`); }
      if(!r.ok || !d.ok) throw new Error(d.message || `HTTP ${r.status}`);
      span.remove();
      cell.classList.remove('has-chip');
      toast('Affectation retirée');
    })
    .catch(err=>toast(err.message||'Erreur réseau'));
  });

  // re-drag
  span.addEventListener('dragstart', e=>{
    e.dataTransfer.setData('chantier_id', chantierId);
    e.dataTransfer.setData('color', span.style.background || '');
    e.dataTransfer.setData('label', label);
    e.dataTransfer.effectAllowed='move';
  });
  span.setAttribute('draggable','true');

  return span;
}

function sameAssignment(cell, chantierId){
  const cur = cell.querySelector('.assign-chip');
  if(!cur) return false;
  return String(cur.dataset.chantierId||'') === String(chantierId||'');
}

function attachDropTargets(){
  document.querySelectorAll('.cell-drop').forEach(cell=>{
    cell.addEventListener('dragover', e=>{ e.preventDefault(); cell.classList.add('dragover'); });
    cell.addEventListener('dragleave', ()=>cell.classList.remove('dragover'));

    cell.addEventListener('drop', e=>{
      e.preventDefault(); cell.classList.remove('dragover');

      const chantierId = Number(e.dataTransfer.getData('chantier_id') || 0);
      const color      = e.dataTransfer.getData('color') || '';
      const label      = e.dataTransfer.getData('label') || 'Chantier';
      if(!chantierId || chantierId <= 0) return; // garde-fou

      const empId = cell.dataset.emp;
      const date  = cell.dataset.date;
      if(!empId || !date) return;

      // si la même affectation est déjà posée, ne rien faire
      if (sameAssignment(cell, chantierId)) {
        toast('Déjà affecté à ce chantier');
        return;
      }

      // backup pour rollback
      const prev = cell.querySelector('.assign-chip');
      const prevHTML = prev ? prev.outerHTML : null;

      // remplace l’existant (1 affectation max / emp / jour)
      cell.querySelector('.assign-chip')?.remove();
      const chip = makeAssign(label, color, chantierId);
      cell.appendChild(chip);
      cell.classList.add('has-chip');

      // API
      fetch(window.API_MOVE,{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        credentials:'same-origin',
        body:new URLSearchParams({ emp_id: empId, chantier_id: String(chantierId), date })
      })
      .then(async r=>{
        const raw = await r.text(); let data;
        try{ data = JSON.parse(raw);}catch{ throw new Error(`HTTP ${r.status} – ${raw.slice(0,200)}`); }
        if(!r.ok || !data.ok) throw new Error(data?.message||`HTTP ${r.status}`);
        toast('Affectation enregistrée');
      })
      .catch(err=>{
        toast(err.message||'Erreur réseau');
        // rollback visuel (en cas d’erreur)
        chip.remove();
        if (prevHTML){
          cell.insertAdjacentHTML('beforeend', prevHTML);
        }
        if (!cell.querySelector('.assign-chip')) {
          cell.classList.remove('has-chip');
        }
      });
    });
  });
}

function filterRows(q){
  q = (q || '').toLowerCase();
  document.querySelectorAll('#gridBody tr').forEach(tr=>{
    const name = (tr.querySelector('td:first-child')?.innerText || '').toLowerCase();
    tr.style.display = name.includes(q) ? '' : 'none';
  });
}

document.addEventListener('DOMContentLoaded', ()=>{
  attachDragSources();
  attachDropTargets();

  const input = document.getElementById('searchInput');
  if (input){
    let t;
    input.addEventListener('input', e=>{
      clearTimeout(t);
      const v = e.target.value;
      t = setTimeout(()=>filterRows(v),120);
    });
  }
});

document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-week-shift]');
  if (!btn) return;

  e.preventDefault();

  const nav  = document.getElementById('weekNav');
  if (!nav) return;

  const shift = parseInt(btn.dataset.weekShift, 10);
  let year    = parseInt(nav.dataset.year, 10);
  let week    = parseInt(nav.dataset.week, 10);

  if (shift === 0) {
    // Aller à la semaine courante
    const now = new Date();
    ({ week, year } = getISOWeekYear(now));
  } else {
    ({ week, year } = addWeeks(week, year, shift));
  }

  // Met à jour l’URL (ex: ?year=2025&week=36) pour que le PHP recharge la bonne semaine
  const url = new URL(window.location.href);
  url.searchParams.set('year', year);
  url.searchParams.set('week', week);
  window.location.href = url.toString();
});

// --- Helpers ISO semaine/année ---
function getISOWeekYear(d) {
  const date = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
  const day = (date.getUTCDay() + 6) % 7;       // 0 = lundi
  date.setUTCDate(date.getUTCDate() - day + 3); // jeudi de la semaine
  const firstThu = new Date(Date.UTC(date.getUTCFullYear(), 0, 4));
  const firstThuDay = (firstThu.getUTCDay() + 6) % 7;
  const week = 1 + Math.round(((date - firstThu) / 86400000 - 3 + firstThuDay) / 7);
  return { week, year: date.getUTCFullYear() };
}

function isoWeekMonday(year, week) {
  const d = new Date(Date.UTC(year, 0, 1 + (week - 1) * 7));
  const dow = (d.getUTCDay() + 6) % 7;
  d.setUTCDate(d.getUTCDate() - dow);
  return d; // lundi
}

function addWeeks(week, year, shift) {
  const d = isoWeekMonday(year, week);
  d.setUTCDate(d.getUTCDate() + shift * 7);
  return getISOWeekYear(d);
}
