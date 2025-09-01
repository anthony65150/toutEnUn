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
      e.dataTransfer.setData('label', ch.childNodes[0].nodeValue.trim());
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
  span.querySelector('.x').addEventListener('click', ()=>{
    const cell = span.closest('.cell-drop');
    if(!cell) return;
    const empId  = cell.dataset.emp;
    const date   = cell.dataset.date;
    fetch(window.API_DELETE,{
      method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({ emp_id: empId, date })
    }).then(r=>r.json()).then(d=>{
      if(!d.ok) throw new Error(d.message||'Erreur');
      span.remove(); toast('Affectation retirée');
    }).catch(err=>toast(err.message||'Erreur réseau'));
  });
  span.addEventListener('dragstart', e=>{
    const p = span.closest('.cell-drop');
    e.dataTransfer.setData('chantier_id', chantierId);
    e.dataTransfer.setData('color', span.style.background || '');
    e.dataTransfer.setData('label', label);
    e.dataTransfer.effectAllowed='move';
  });
  span.setAttribute('draggable','true');
  return span;
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

      const empId = cell.dataset.emp;
      const date  = cell.dataset.date;

      // remplace l’existant (1 affectation max / emp / jour)
      cell.querySelector('.assign-chip')?.remove();
      cell.appendChild(makeAssign(label, color, chantierId));

      // API
      fetch(window.API_MOVE,{
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:new URLSearchParams({ emp_id: empId, chantier_id: String(chantierId), date })
      }).then(async r=>{
        const raw = await r.text(); let data;
        try{ data = JSON.parse(raw);}catch{ throw new Error(`HTTP ${r.status} – ${raw.slice(0,200)}`); }
        if(!r.ok || !data.ok) throw new Error(data?.message||`HTTP ${r.status}`);
        toast('Affectation enregistrée');
      }).catch(err=>{
        toast(err.message||'Erreur réseau');
        // rollback visuel (en cas d’erreur) : on retire ce qu’on a ajouté
        cell.querySelector('.assign-chip')?.remove();
      });
    });
  });
}

function filterRows(q){
  q = q.toLowerCase();
  document.querySelectorAll('#gridBody tr').forEach(tr=>{
    const name = (tr.querySelector('td:first-child')?.innerText || '').toLowerCase();
    tr.style.display = name.includes(q) ? '' : 'none';
  });
}

document.addEventListener('DOMContentLoaded', ()=>{
  attachDragSources();
  attachDropTargets();
  const input = document.getElementById('searchInput');
  if (input){ input.addEventListener('input', e=>filterRows(e.target.value)); }
});
