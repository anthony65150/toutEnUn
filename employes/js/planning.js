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
